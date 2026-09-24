<?php

namespace Modules\WhatsAppVendorConcierge\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;
use Modules\WhatsAppVendorConcierge\app\Services\AiManager;

class AiTestModelsCommand extends Command
{
    protected $signature = 'ai:test-models {connection_id? : ID of the connection to test}';
    protected $description = 'Test all enabled models and automatically disable broken/deprecated ones';

    public function handle(AiManager $aiManager)
    {
        $query = AiProviderConnection::where('is_active', true);
        if ($this->argument('connection_id')) {
            $query->where('id', $this->argument('connection_id'));
        }
        
        $connections = $query->get();
        if ($connections->isEmpty()) {
            $this->warn('No active connections found.');
            return;
        }

        foreach ($connections as $connection) {
            $this->info("Testing models for connection: {$connection->name}...");
            $models = $connection->models()->where('is_enabled', true)->get();
            
            if ($models->isEmpty()) {
                $this->line(" No enabled models.");
                continue;
            }

            try {
                $driver = $aiManager->driver($connection->id);
            } catch (\Throwable $e) {
                $this->error(" Failed to initialize driver: " . $e->getMessage());
                continue;
            }

            $disabledCount = 0;

            foreach ($models as $model) {
                $this->line(" Testing {$model->model_id}...");
                try {
                    $driver->chat()->create([
                        'model' => $model->model_id,
                        'messages' => [
                            ['role' => 'user', 'content' => 'hello']
                        ]
                    ]);
                    $this->info("   -> Success!");
                } catch (\Throwable $e) {
                    $msg = $e->getMessage();
                    $msgLower = strtolower($msg);
                    
                    if (str_contains($msgLower, '401') || str_contains($msgLower, '402') || str_contains($msgLower, 'insufficient balance')) {
                        $this->warn("   -> Auth/Balance Error (Keeping enabled): " . strtok($msg, "\n"));
                        break; // Stop testing other models for this connection since auth/balance is account-wide!
                    }

                    $isDead = str_contains($msgLower, '404') || str_contains($msgLower, '400') || str_contains($msgLower, 'does not exist') || str_contains($msgLower, 'decommissioned');
                    
                    if ($isDead) {
                        $model->update(['is_enabled' => false]);
                        $disabledCount++;
                        $this->error("   -> Disabled (Dead model): " . strtok($msg, "\n"));
                    } else {
                        $this->warn("   -> Failed (Unknown error): " . strtok($msg, "\n"));
                    }
                }
            }
            
            if ($disabledCount > 0) {
                $this->info(" Disabled {$disabledCount} broken models for {$connection->name}.");
            }
        }
        
        $this->info('Done.');
    }
}
