<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo app(Modules\WhatsAppVendorConcierge\app\Services\VendorAccessNoticeService::class)->details(App\Models\Store::findOrFail(2))['login_url'].PHP_EOL;
