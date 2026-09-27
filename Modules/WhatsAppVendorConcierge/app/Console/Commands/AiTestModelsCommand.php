<?php

namespace Modules\WhatsAppVendorConcierge\app\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;
use Modules\WhatsAppVendorConcierge\app\Services\AiAdapters\AdapterFactory;

class AiTestModelsCommand extends Command
{
    protected $signature = 'ai:test-models {connection_id : Connection to test}';
    protected $description = 'Run a small real text probe against enabled models of one connection (consumes provider quota)';

    public function handle(): int
    {
        $connection = AiProviderConnection::with(['definition', 'models'])->find($this->argument('connection_id'));
        if (!$connection || !$connection->is_active) {
            $this->error('Choose an active connection.');
            return self::FAILURE;
        }
        $models = $connection->models->where('is_enabled', true);
        if ($models->isEmpty()) {
            $this->error('Enable the model you intend to test first.');
            return self::FAILURE;
        }
        $agent = new class implements Agent {
            use Promptable;
            public function instructions(): string { return 'Reply with OK only.'; }
        };
        $adapter = AdapterFactory::forConnection($connection);
        $failed = false;
        foreach ($models as $model) {
            try {
                $result = $adapter->invokeAgent($connection, $model, $agent, 'Connection test. Reply OK.', ['timeout' => 20]);
                if (trim((string) $result['response']) === '') throw new \RuntimeException('Empty response.');
                $this->info($model->model_id.': text inference passed (tool execution not tested).');
            } catch (\Throwable $e) {
                $failed = true;
                $this->error($model->model_id.': probe failed ('.$adapter->normaliseError($e)['type'].'). Check provider dashboard.');
            }
        }
        if (!$failed) $connection->update(['status' => 'inference_verified', 'last_tested_at' => now(), 'last_error' => null]);
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
