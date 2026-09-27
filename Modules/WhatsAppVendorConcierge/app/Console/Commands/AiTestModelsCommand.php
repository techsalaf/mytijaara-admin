<?php

namespace Modules\WhatsAppVendorConcierge\app\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;
use Modules\WhatsAppVendorConcierge\app\Services\AiAdapters\AdapterFactory;

class AiTestModelsCommand extends Command
{
    protected $signature = 'ai:test-models {connection_id : Connection to test} {--tools : Verify a harmless real tool call as well as text} {--model= : Limit the probe to one enabled model ID}';
    protected $description = 'Run a small real text probe against enabled models of one connection (consumes provider quota)';

    public function handle(): int
    {
        $connection = AiProviderConnection::with(['definition', 'models'])->find($this->argument('connection_id'));
        if (!$connection || !$connection->is_active) {
            $this->error('Choose an active connection.');
            return self::FAILURE;
        }
        $models = $connection->models->where('is_enabled', true);
        if ($this->option('model')) $models = $models->where('model_id', $this->option('model'));
        if ($models->isEmpty()) {
            $this->error('Enable the model you intend to test first.');
            return self::FAILURE;
        }
        $adapter = AdapterFactory::forConnection($connection);
        $failed = false;
        foreach ($models as $model) {
            $agent = new \Modules\WhatsAppVendorConcierge\app\Agents\ProviderHealthProbeAgent((bool) $this->option('tools'));
            $kind = $this->option('tools') ? 'tools' : 'text';
            $passed = false;
            try {
                $result = $adapter->invokeAgent($connection, $model, $agent, 'Connection test. Reply OK.', ['timeout' => 20]);
                if (trim((string) $result['response']) === '') throw new \RuntimeException('Empty response.');
                if ($this->option('tools') && $agent->probe->calls < 1) throw new \RuntimeException('Tool was not executed.');
                $passed = true;
                $this->info($model->model_id.': '.$kind.' probe passed.');
            } catch (\Throwable $e) {
                $failed = true;
                $this->error($model->model_id.': probe failed ('.$adapter->normaliseError($e)['type'].'). Check provider dashboard.');
            }
            $verification = $connection->verification ?? [];
            $verification['models'][$model->model_id][$kind] = ['passed' => $passed, 'at' => now()->toIso8601String()];
            $connection->update(['verification' => $verification]);
        }
        if (!$failed) $connection->update(['status' => $this->option('tools') ? 'tool_calling_verified' : 'inference_verified', 'last_tested_at' => now(), 'last_error' => null]);
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
