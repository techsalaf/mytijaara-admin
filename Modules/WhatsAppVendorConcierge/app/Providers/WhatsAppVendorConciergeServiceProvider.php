<?php

namespace Modules\WhatsAppVendorConcierge\app\Providers;

use App\Events\VendorApplicationStatusChanged;
use App\Models\Vendor;
use App\Services\RegistrationPolicyService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\WhatsAppVendorConcierge\app\Console\AiHealthCommand;
use Modules\WhatsAppVendorConcierge\app\Console\Commands\AiTestModelsCommand;
use Modules\WhatsAppVendorConcierge\app\Console\Commands\CheckTemplates;
use Modules\WhatsAppVendorConcierge\app\Console\Commands\CleanupFlowControl;
use Modules\WhatsAppVendorConcierge\app\Console\Commands\CleanupMedia;
use Modules\WhatsAppVendorConcierge\app\Console\Commands\ConciergeDiagnoseCommand;
use Modules\WhatsAppVendorConcierge\app\Console\Commands\ConciergeHealthCheckCommand;
use Modules\WhatsAppVendorConcierge\app\Console\Commands\ConciergeRecoverCommand;
use Modules\WhatsAppVendorConcierge\app\Console\Commands\LaunchTaxonomyCommand;
use Modules\WhatsAppVendorConcierge\app\Console\Commands\MigrateLegacyAiProviders;
use Modules\WhatsAppVendorConcierge\app\Console\Commands\Preflight;
use Modules\WhatsAppVendorConcierge\app\Console\Commands\ProcessStuckSessions;
use Modules\WhatsAppVendorConcierge\app\Console\Commands\ReconcileDeliveryLogsCommand;
use Modules\WhatsAppVendorConcierge\app\Console\Commands\RefreshAiModelsCommand;
use Modules\WhatsAppVendorConcierge\app\Console\Commands\ReplayInboundCommand;
use Modules\WhatsAppVendorConcierge\app\Console\Commands\ReviewInboundCommand;
use Modules\WhatsAppVendorConcierge\app\Console\Commands\SendActiveStoreOutreachCommand;
use Modules\WhatsAppVendorConcierge\app\Console\Commands\SyncVendorFlow;
use Modules\WhatsAppVendorConcierge\app\Console\Commands\ValidateVendorFlow;
use Modules\WhatsAppVendorConcierge\app\Console\Commands\VendorAccessNoticeCommand;
use Modules\WhatsAppVendorConcierge\app\Console\Commands\VendorFlowOperations;
use Modules\WhatsAppVendorConcierge\app\Http\Middleware\InjectAdminSidebarMenu;
use Modules\WhatsAppVendorConcierge\app\Listeners\SendWhatsAppStatusNotificationOnDomainEvent;
use Modules\WhatsAppVendorConcierge\app\Observers\VendorApprovalObserver;
use Modules\WhatsAppVendorConcierge\app\Services\ConversationManager;
use Modules\WhatsAppVendorConcierge\app\Services\FlowControl\RuntimeSettings;
use Modules\WhatsAppVendorConcierge\app\Services\SubscriptionLifecycleService;
use Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService;
use Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway;

class WhatsAppVendorConciergeServiceProvider extends ServiceProvider
{
    protected string $moduleName = 'WhatsAppVendorConcierge';

    protected string $moduleNameLower = 'whatsapp-vendor-concierge';

    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        $this->registerCommands();
        $this->registerCommandSchedules();
        $this->registerTranslations();
        $this->registerConfig();
        $this->registerViews();
        $this->loadMigrationsFrom(module_path($this->moduleName, 'database/migrations'));
        $this->loadRoutesFrom(module_path($this->moduleName, 'routes/web.php'));
        $this->loadRoutesFrom(module_path($this->moduleName, 'routes/api.php'));

        // Inject admin sidebar menu without modifying core files
        $this->app['router']->pushMiddlewareToGroup('web', InjectAdminSidebarMenu::class);

        // Register vendor approval/denial observer for WhatsApp notifications
        Vendor::observe(VendorApprovalObserver::class);

        // Register canonical domain event listener for status transitions
        Event::listen(
            VendorApplicationStatusChanged::class,
            SendWhatsAppStatusNotificationOnDomainEvent::class
        );
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        // Module-owned adapter: canonical registration keeps its core service and evidence contract.
        $this->app->resolving(RegistrationPolicyService::class, function () {
            app(RuntimeSettings::class)->apply();
        });
        $this->app->register(RouteServiceProvider::class);

        // Bind core services
        $this->app->singleton(WhatsAppGateway::class);
        $this->app->singleton(VendorOnboardingService::class);
        $this->app->singleton(ConversationManager::class);
        $this->app->singleton(SubscriptionLifecycleService::class);
    }

    /**
     * Register commands in the format of Command::class
     */
    protected function registerCommands(): void
    {
        $this->commands([
            CleanupFlowControl::class,
            VendorFlowOperations::class,
            ValidateVendorFlow::class,
            SyncVendorFlow::class,
            ReviewInboundCommand::class,
            ReplayInboundCommand::class,
            AiTestModelsCommand::class,
            LaunchTaxonomyCommand::class,
            VendorAccessNoticeCommand::class,
            ReconcileDeliveryLogsCommand::class,
            CleanupMedia::class,
            ProcessStuckSessions::class,
            Preflight::class,
            CheckTemplates::class,
            MigrateLegacyAiProviders::class,
            AiHealthCommand::class,
            ConciergeDiagnoseCommand::class,
            ConciergeRecoverCommand::class,
            ConciergeHealthCheckCommand::class,
            RefreshAiModelsCommand::class,
            SendActiveStoreOutreachCommand::class,
        ]);
    }

    /**
     * Register command Schedules.
     */
    protected function registerCommandSchedules(): void
    {
        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);

            $schedule->exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('scripts/prune-releases.php')).' '.escapeshellarg(base_path()).' --force')->dailyAt('03:20')->withoutOverlapping();

            // Cleanup old media files daily
            $schedule->command('whatsapp:flow-control-cleanup --execute')->everyFifteenMinutes()->withoutOverlapping();
            $schedule->command('whatsapp:cleanup-media')
                ->dailyAt('03:00')
                ->runInBackground()
                ->withoutOverlapping()
                ->appendOutputTo(storage_path('logs/whatsapp-schedule.log'));

            // Process stuck onboarding sessions
            $schedule->command('whatsapp:process-stuck-sessions')
                ->everyFifteenMinutes()
                ->runInBackground()
                ->withoutOverlapping()
                ->appendOutputTo(storage_path('logs/whatsapp-schedule.log'));

            // Run Concierge Health Check scan every fifteen minutes
            $schedule->command('whatsapp:concierge-health-check')
                ->everyFifteenMinutes()
                ->runInBackground()
                ->withoutOverlapping()
                ->appendOutputTo(storage_path('logs/whatsapp-schedule.log'));

            // Discovery refresh only updates provider catalogues; it performs no vendor inference.
            $schedule->command('whatsapp:refresh-ai-models')
                ->dailyAt('02:40')
                ->runInBackground()
                ->withoutOverlapping()
                ->appendOutputTo(storage_path('logs/whatsapp-schedule.log'));
        });
    }

    /**
     * Register translations.
     */
    public function registerTranslations(): void
    {
        $langPath = resource_path('lang/modules/'.$this->moduleNameLower);

        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, $this->moduleNameLower);
            $this->loadJsonTranslationsFrom($langPath);
        } else {
            $this->loadTranslationsFrom(module_path($this->moduleName, 'lang'), $this->moduleNameLower);
            $this->loadJsonTranslationsFrom(module_path($this->moduleName, 'lang'));
        }
    }

    /**
     * Register config.
     */
    protected function registerConfig(): void
    {
        $this->publishes([module_path($this->moduleName, 'config/config.php') => config_path($this->moduleNameLower.'.php')], 'config');
        $this->mergeConfigFrom(module_path($this->moduleName, 'config/config.php'), $this->moduleNameLower);
        $this->mergeConfigFrom(module_path($this->moduleName, 'config/flow.php'), 'whatsapp-vendor-flow');
        config(['filesystems.disks.vendor_flow_private' => ['driver' => 'local', 'root' => config('whatsapp-vendor-flow.private_root'), 'visibility' => 'private', 'throw' => true]]);
    }

    /**
     * Register views.
     */
    public function registerViews(): void
    {
        $viewPath = resource_path('views/modules/'.$this->moduleNameLower);
        $sourcePath = module_path($this->moduleName, 'resources/views');

        if (is_dir($sourcePath)) {
            $this->publishes([$sourcePath => $viewPath], ['views', $this->moduleNameLower.'-module-views']);
            $this->loadViewsFrom(array_merge($this->getPublishableViewPaths(), [$sourcePath]), $this->moduleNameLower);
            $this->loadViewsFrom(array_merge($this->getPublishableViewPaths(), [$sourcePath]), 'whatsappvendorconcierge');
        }

        $componentNamespace = str_replace('/', '\\', config('modules.namespace').'\\'.$this->moduleName.'\\'.config('modules.paths.generator.component-class.path'));
        Blade::componentNamespace($componentNamespace, $this->moduleNameLower);
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            WhatsAppGateway::class,
            VendorOnboardingService::class,
            ConversationManager::class,
            SubscriptionLifecycleService::class,
        ];
    }

    private function getPublishableViewPaths(): array
    {
        $paths = [];
        foreach (config('view.paths') as $path) {
            if (is_dir($path.'/modules/'.$this->moduleNameLower)) {
                $paths[] = $path.'/modules/'.$this->moduleNameLower;
            }
        }

        return $paths;
    }
}
