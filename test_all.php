<?php
require_once __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderModel;
use Modules\WhatsAppVendorConcierge\app\Services\AiAdapters\AdapterFactory;

$connections = AiProviderConnection::where('is_active', true)->get();

if ($connections->isEmpty()) {
    echo "No active connections found.\n";
    exit;
}

$agent = new class implements \Laravel\Ai\Contracts\Agent {
    use \Laravel\Ai\Promptable;
    public function instructions(): string {
        return "You are a helpful assistant.";
    }
};

foreach ($connections as $connection) {
    echo "Testing models for connection: {$connection->name}...\n";
    $models = $connection->models()->where('is_enabled', true)->get();
    
    if ($models->isEmpty()) {
        echo " No enabled models.\n";
        continue;
    }

    try {
        $adapter = AdapterFactory::forConnection($connection);
    } catch (\Throwable $e) {
        echo " Failed to initialize adapter: " . $e->getMessage() . "\n";
        continue;
    }

    $disabledCount = 0;

    foreach ($models as $model) {
        echo " Testing {$model->model_id}...\n";
        try {
            // max_tokens = 5 to save cost and speed
            $result = $adapter->invokeAgent(
                $connection,
                $model,
                $agent,
                "Say 'hi'",
                ['max_tokens' => 5]
            );
            echo "   -> Success! ({$result['latency_ms']}ms)\n";
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $msgLower = strtolower($msg);
            
            if (str_contains($msgLower, '401') || str_contains($msgLower, '402') || str_contains($msgLower, 'insufficient balance')) {
                echo "   -> Auth/Balance Error (Keeping enabled): " . strtok($msg, "\n") . "\n";
                break; // Stop testing other models for this connection since auth/balance is account-wide!
            }

            $isDead = str_contains($msgLower, '404') || str_contains($msgLower, '400') || str_contains($msgLower, 'does not exist') || str_contains($msgLower, 'decommissioned');
            
            if ($isDead) {
                $model->update(['is_enabled' => false]);
                $disabledCount++;
                echo "   -> Disabled (Dead model): " . strtok($msg, "\n") . "\n";
            } else {
                echo "   -> Failed (Unknown error): " . strtok($msg, "\n") . "\n";
            }
        }
    }
    
    if ($disabledCount > 0) {
        echo " Disabled {$disabledCount} broken models for {$connection->name}.\n";
    }
}

echo "Done.\n";
