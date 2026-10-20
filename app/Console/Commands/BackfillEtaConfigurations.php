<?php

namespace App\Console\Commands;

use App\Models\EtaConfiguration;
use App\Models\Zone;
use App\Services\System\ModuleService;
use App\Services\Zone\EtaConfigurationService;
use App\Services\Zone\ZoneService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Reproduce standard ETA configurations per (zone, module) for all ETA-capable modules
 * and optionally reactivate zones that become ready.
 *
 *   php artisan eta:backfill                  # dry run — writes nothing
 *   php artisan eta:backfill --apply          # writes configurations and activates ready zones
 */
class BackfillEtaConfigurations extends Command
{
    protected $signature = 'eta:backfill
                            {--apply : Write the configurations. Without this the command only reports.}
                            {--no-activate : Do not activate zones even if they become ready.}';

    protected $description = 'Create an active ETA configuration per (zone, module) reproducing standard delivery timings';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $activate = ! (bool) $this->option('no-activate');

        $zones = Zone::all();
        $moduleService = app(ModuleService::class);
        $etaService = app(EtaConfigurationService::class);
        $etaCapable = $moduleService->etaCapableModuleIds();
        $parcelIds = $moduleService->moduleIdsOfType('parcel');

        $plans = [];
        foreach ($zones as $zone) {
            $connected = $zone->connectedModuleIds();
            $capableInZone = array_values(array_intersect($connected, $etaCapable));
            $timedInZone = $zone->timedModuleIds();
            $missing = array_values(array_diff($capableInZone, $timedInZone));

            if ($missing !== []) {
                $plans[] = [
                    'zone' => $zone,
                    'missing' => $missing,
                ];
            }
        }

        if (empty($plans)) {
            $this->info('No connected (zone, module) pairs without an active ETA configuration. Nothing to do.');

            if ($activate) {
                $reactivated = $this->activateReadyZones($apply);
                if ($reactivated > 0) {
                    $this->info("Activated {$reactivated} ready zone(s).");
                }
            }

            return self::SUCCESS;
        }

        $this->line('');
        $this->info(($apply ? 'APPLYING' : 'DRY RUN — nothing will be written').'  ·  '.count($plans).' zone(s) missing ETA setups');
        $this->line('');

        DB::beginTransaction();

        $createdCount = 0;
        $tableRows = [];

        foreach ($plans as $plan) {
            /** @var Zone $zone */
            $zone = $plan['zone'];
            $missing = $plan['missing'];
            $hasParcel = array_intersect($missing, $parcelIds) !== [];

            $configData = [
                'name' => trim(($zone->name ?? 'Zone '.$zone->id).' Standard Delivery ETA'),
                'zone_id' => $zone->id,
                'module_ids' => $missing,
                'calculation_method' => EtaConfiguration::METHOD_DISTANCE,
                'minimum_delivery_time' => 20,
                'preparation_buffer' => 10,
                'transit_buffer' => 10,
                'time_gap' => 5,
                'parcel_minimum_delivery_time' => $hasParcel ? 30 : null,
                'parcel_transit_buffer' => $hasParcel ? 30 : null,
                'parcel_time_gap' => $hasParcel ? 10 : null,
                'status' => 1,
            ];

            $setup = $etaService->create($configData);
            $createdCount++;

            $tableRows[] = [
                $zone->id,
                mb_substr($zone->name, 0, 22),
                implode(',', $missing),
                $setup->calculation_method,
                "{$setup->minimum_delivery_time}m (prep: {$setup->preparation_buffer}m, tr: {$setup->transit_buffer}m)",
                $zone->status ? 'ON' : 'off',
            ];
        }

        $activatedCount = 0;
        if ($activate) {
            $activatedCount = $this->activateReadyZones(true);
        }

        $this->table(
            ['Zone ID', 'Zone Name', 'Modules Covered', 'Method', 'Timings', 'Status Before'],
            $tableRows
        );

        if (! $apply) {
            DB::rollBack();
            $this->line('');
            $this->info("Dry run complete: {$createdCount} ETA configuration(s) would be created across ".count($plans)." zone(s).");
            if ($activate) {
                $this->info("{$activatedCount} zone(s) would be activated.");
            }
            $this->info('Rolled back. Re-run with --apply to commit these changes.');

            return self::SUCCESS;
        }

        DB::commit();

        Zone::forgetEtaCapableModules();
        $etaService->forgetActive();

        try {
            Artisan::call('cache:clear');
        } catch (\Throwable $e) {
            // cache clear fallback
        }

        $this->line('');
        $this->info("Committed: {$createdCount} ETA configuration(s) created.");
        if ($activate) {
            $this->info("{$activatedCount} zone(s) activated and serving customers.");
        }

        return self::SUCCESS;
    }

    private function activateReadyZones(bool $commit): int
    {
        Zone::forgetEtaCapableModules();
        $zones = Zone::all();
        $count = 0;

        foreach ($zones as $zone) {
            if ((int) $zone->status === 0 && $zone->isReadyToActivate()) {
                if ($commit) {
                    DB::table('zones')->where('id', $zone->id)->update(['status' => 1]);
                }
                $count++;
            }
        }

        return $count;
    }
}
