<?php
require_once __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;

$msgs = WhatsAppMessage::whereHas('conversation', function($q) {
    $q->whereHas('contact', function($q2) {
        $q2->where('phone_number', '2347062716154');
    });
})->orderBy('id', 'desc')->limit(20)->get();

foreach($msgs->reverse() as $m) {
    echo "[$m->created_at] $m->direction ($m->type): $m->raw_text\n";
}
