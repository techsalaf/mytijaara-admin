<?php
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$c = \Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection::whereHas('definition', function($q){$q->where('slug', 'groq');})->first();
if ($c) {
    echo "API KEY: " . $c->getApiKey() . "\n";
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model' => 'llama-3.3-70b-versatile',
        'messages' => [['role' => 'user', 'content' => 'hi']]
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $c->getApiKey()
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    echo "Groq Result: " . $code . " -> " . $res . "\n";
} else {
    echo "No Groq connection\n";
}
