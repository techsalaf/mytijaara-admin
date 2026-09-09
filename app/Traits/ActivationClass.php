<?php

namespace App\Traits;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
// use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * NulledMaster - All license checks permanently bypassed.
 * Software is free and open source for everyone.
 */
trait ActivationClass
{
    public function is_local(): bool
    {
        // NulledMaster: Always return true to bypass all server verification
        return true;
    }

    public function getDomain(): string
    {
        return str_replace(["http://", "https://", "www."], "", url('/'));
    }

    public function getSystemAddonCacheKey(string|null $app = 'default'): string
    {
        $appName = env('APP_NAME').'_cache';
        return str_replace('-', '_', Str::slug($appName.'cache_system_addons_for_' . $app . '_' . $this->getDomain()));
    }

    public function getAddonsConfig(): array
    {
        // NulledMaster: Always return all addons as active
        $apps = ['admin_panel', 'vendor_panel', 'user_app', 'vendor_app', 'deliveryman_app', 'react_web'];
        $appConfig = [];
        foreach ($apps as $app) {
            $appConfig[$app] = [
                "active" => "1",
                "name" => "NulledMaster",
                "email" => "free@nulledmaster.com",
                "username" => "NulledMaster",
                "purchase_key" => "NULLED-FREE-FOR-ALL",
                "software_id" => "",
                "domain" => $this->getDomain(),
                "software_type" => $app == 'admin_panel' ? "product" : 'addon',
            ];
        }
        return $appConfig;
    }

    public function getCacheTimeoutByDays(int $days = 3): int
    {
        return 60 * 60 * 24 * $days;
    }

    public function getRequestConfig(string|null $name = null, string|null $email = null, string|null $username = null, string|null $purchaseKey = null, string|null $softwareId = null, string|null $softwareType = null): array
    {
        // NulledMaster: No server call, always return active
        return [
            "active" => 1,
            "name" => trim($name ?? 'NulledMaster'),
            "email" => trim($email ?? 'free@nulledmaster.com'),
            "username" => trim($username ?? 'NulledMaster'),
            "purchase_key" => $purchaseKey ?? 'NULLED-FREE-FOR-ALL',
            "software_id" => $softwareId ?? (defined('SOFTWARE_ID') ? SOFTWARE_ID : ''),
            "domain" => $this->getDomain(),
            "software_type" => $softwareType ?? 'product',
        ];
    }

    public function checkActivationCache(string|null $app)
    {
        // NulledMaster: Always return true, skip all activation checks
        return true;
    }

    public function updateActivationConfig($app, $response): void
    {
        // NulledMaster: Force active=1 before saving
        $response['active'] = 1;
        $config = $this->getAddonsConfig();
        $config[$app] = $response;
        $configContents = "<?php return " . var_export($config, true) . ";";
        file_put_contents(base_path('config/system-addons.php'), $configContents);
        $cacheKey = $this->getSystemAddonCacheKey(app: $app);
        Cache::forget($cacheKey);
    }
}

