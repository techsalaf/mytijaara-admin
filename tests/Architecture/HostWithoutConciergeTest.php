<?php

namespace Tests\Architecture;

use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\PreserveGlobalState;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class HostWithoutConciergeTest extends \Tests\TestCase
{
    private ?string $statuses = null;
    private $denyModuleAutoload;

    public function createApplication()
    {
        $this->denyModuleAutoload = static function (string $class): void {
            if (str_starts_with($class, 'Modules\\WhatsAppVendorConcierge\\')) {
                throw new \LogicException('Optional module class is unavailable: '.$class);
            }
        };
        spl_autoload_register($this->denyModuleAutoload, true, true);
        $app = require __DIR__.'/../../bootstrap/app.php';
        $this->statuses = tempnam(sys_get_temp_dir(), 'host-modules-');
        $statuses = json_decode(file_get_contents(__DIR__.'/../../modules_statuses.json'), true);
        $statuses['WhatsAppVendorConcierge'] = false;
        file_put_contents($this->statuses, json_encode($statuses));
        $app->afterBootstrapping(LoadConfiguration::class, function ($app): void {
            $app['config']->set('modules.activators.file.statuses-file', $this->statuses);
            $app['config']->set('modules.cache.enabled', false);
            $app['config']->set('database.default', 'sqlite');
            $app['config']->set('database.connections.sqlite.database', ':memory:');
            $app['config']->set('cache.default', 'array');
            $app['config']->set('session.driver', 'array');
            $app['config']->set('mail.default', 'array');
            $app['config']->set('app.debug', false);
            $app['config']->set('logging.default', 'isolation');
            $app['config']->set('logging.channels.isolation', ['driver' => 'monolog', 'handler' => \Monolog\Handler\NullHandler::class]);
        });
        $app->make(Kernel::class)->bootstrap();
        return $app;
    }

    protected function tearDown(): void
    {
        try { parent::tearDown(); } finally {
            if ($this->denyModuleAutoload) spl_autoload_unregister($this->denyModuleAutoload);
            if ($this->statuses && is_file($this->statuses)) unlink($this->statuses);
        }
    }

    public function test_host_boots_and_registers_client_routes_without_module_classes(): void
    {
        $routes = collect($this->app['router']->getRoutes()->getRoutes());
        foreach (['api/v1/auth/vendor/register', 'api/v1/vendor/update-active-status'] as $uri) {
            $route = $routes->first(fn ($route) => $route->uri() === $uri);
            $this->assertNotNull($route, $uri);
            $this->assertContains('POST', $route->methods());
        }
        $this->assertFalse($routes->contains(fn ($route) => $route->uri() === 'webhooks/whatsapp'));
        $this->assertFalse($this->app['modules']->isEnabled('WhatsAppVendorConcierge'));
    }

    public function test_public_registration_validation_does_not_load_module_classes_or_tables(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('business_settings')) \Illuminate\Support\Facades\Schema::create('business_settings', function ($table) {
            $table->id(); $table->string('key'); $table->text('value')->nullable();
        });
        $response = $this->app->make(\App\Http\Controllers\VendorController::class)->store(new Request());
        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayHasKey('errors', $response->getData(true));
    }

    public function test_existing_host_controllers_can_be_resolved_without_module_classes(): void
    {
        foreach ([
            \App\Http\Controllers\Api\V1\Auth\VendorLoginController::class,
            \App\Http\Controllers\Api\V1\Vendor\VendorController::class,
            \App\Http\Controllers\Vendor\BusinessSettingsController::class,
            \App\Http\Controllers\Vendor\ItemController::class,
            \App\Http\Controllers\Vendor\OrderController::class,
            \App\Http\Controllers\Admin\VendorController::class,
        ] as $controller) {
            $this->assertInstanceOf($controller, $this->app->make($controller));
        }
    }
}
