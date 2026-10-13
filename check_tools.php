<?php
require_once __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$models = \Modules\WhatsAppVendorConcierge\app\Models\AiProviderModel::where('supports_tool_calling', true)->get();
echo "Models with tools: " . $models->count() . "\n";
foreach($models as $m) {
    echo $m->model_id . "\n";
}
