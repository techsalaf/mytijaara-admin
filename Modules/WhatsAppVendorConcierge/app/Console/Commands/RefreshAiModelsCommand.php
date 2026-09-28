<?php

namespace Modules\WhatsAppVendorConcierge\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;
use Modules\WhatsAppVendorConcierge\app\Services\ModelDiscoveryService;

class RefreshAiModelsCommand extends Command
{
    protected $signature = 'whatsapp:refresh-ai-models {--connection= : Refresh only one connection ID} {--force : Include connections refreshed within the last day}';
    protected $description = 'Refresh active AI provider model catalogues without sending vendor messages or running inference';

    public function handle(ModelDiscoveryService $discovery): int
    {
        $query = AiProviderConnection::with('definition')->where('is_active', true);
        if ($this->option('connection')) $query->whereKey((int) $this->option('connection'));
        $connections = $query->get();
        if ($connections->isEmpty()) {
            $this->info('No active AI provider connections require a refresh.');
            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($connections as $connection) {
            $last = data_get($connection->verification, 'discovery.at');
            if (!$this->option('force') && $last && now()->diffInHours($last) < 24) {
                $this->line("SKIP #{$connection->id}: refreshed less than 24 hours ago.");
                continue;
            }
            try {
                $result = $discovery->syncConnectionModels($connection);
                $this->line("OK #{$connection->id}: {$result['synced']} models synchronized, {$result['new']} new.");
            } catch (\Throwable $e) {
                $failed++;
                $connection->update(['last_error' => 'Model refresh failed: '.get_class($e)]);
                report($e);
                $this->error("FAIL #{$connection->id}: refresh could not complete.");
            }
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
