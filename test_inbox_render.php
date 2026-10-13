<?php
require_once __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$admin = App\Models\Admin::first();
if ($admin) {
    auth('admin')->login($admin);
}

$request = Illuminate\Http\Request::create('/admin/whatsapp/inbox', 'GET');
$response = $kernel->handle($request);
echo "Inbox GET Status: " . $response->getStatusCode() . "\n";
if ($response->getStatusCode() !== 200) {
    echo "Snippet: " . substr($response->getContent(), 0, 500) . "\n";
}
