<?php
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$slugs = ['cerebras','cloudflare_workers_ai','openrouter_free'];
$definitions = Modules\WhatsAppVendorConcierge\app\Models\AiProviderDefinition::whereIn('slug', $slugs)->pluck('slug')->all();
$count = Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection::whereHas('definition', fn($q) => $q->whereIn('slug', $slugs))->count();
echo json_encode(['definitions'=>$definitions,'new_provider_accounts'=>$count,'probe_command_registered'=>array_key_exists('ai:test-models',Illuminate\Support\Facades\Artisan::all()),'adapter_sha256'=>hash_file('sha256',getcwd().'/Modules/WhatsAppVendorConcierge/app/Services/AiAdapters/CerebrasAdapter.php')], JSON_PRETTY_PRINT).PHP_EOL;
