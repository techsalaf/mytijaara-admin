<?php
require_once __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$c = \Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection::whereHas('definition', function($q){$q->where('slug', 'nvidia_nim');})->first();
if ($c) {
    $res = \Illuminate\Support\Facades\Http::withToken($c->getApiKey())
        ->post('https://integrate.api.nvidia.com/v1/chat/completions', [
            'model' => 'meta/llama3-8b-instruct',
            'messages' => [['role' => 'user', 'content' => 'hi']]
        ]);
    echo $res->status() . " -> " . $res->body() . "\n";
} else {
    echo "No NVIDIA connection\n";
}
