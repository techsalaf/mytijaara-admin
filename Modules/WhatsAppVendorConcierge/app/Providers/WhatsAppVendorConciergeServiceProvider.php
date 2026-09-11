<?php

namespace Modules\WhatsAppVendorConcierge\app\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

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

        // Register vendor approval/denial observer for WhatsApp notifications
        \App\Models\Vendor::observe(\Modules\WhatsAppVendorConcierge\app\Observers\VendorApprovalObserver::class);
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->register(RouteServiceProvider::class);

        // Bind core services
        $this->app->singleton(\Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway::class);
        $this->app->singleton(\Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService::class);
        $this->app->singleton(\Modules\WhatsAppVendorConcierge\app\Services\ConversationManager::class);
    }

    /**
     * Register commands in the format of Command::class
     */
    protected function registerCommands(): void
    {
        $this->commands([
            // \Modules\WhatsAppVendorConcierge\app\Console\Commands\CleanupMedia::class,
        ]);
    }

    /**
     * Register command Schedules.
     */
    protected function registerCommandSchedules(): void
    {
        $this->app->booted(function () {
            $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);

            // Cleanup old media files daily
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
    }

    /**
     * Register views.
     */
    public function registerViews(): void
    {
        $viewPath = resource_path('views/modules/'.$this->moduleNameLower);
        $sourcePath = module_path($this->moduleName, 'resources/views');

        $this->publishes([$sourcePath => $viewPath], ['views', $this->moduleNameLower.'-module-views']);

        $this->loadViewsFrom(array_merge($this->getPublishableViewPaths(), [$sourcePath]), $this->moduleNameLower);

        $componentNamespace = str_replace('/', '\\', config('modules.namespace').'\\'.$this->moduleName.'\\'.config('modules.paths.generator.component-class.path'));
        Blade::componentNamespace($componentNamespace, $this->moduleNameLower);
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            \Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway::class,
            \Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService::class,
            \Modules\WhatsAppVendorConcierge\app\Services\ConversationManager::class,
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