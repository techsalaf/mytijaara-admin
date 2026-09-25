<?php
require_once __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Connection: " . config('whatsapp-vendor-concierge.queue.connection', 'database') . "\n";
echo "Queue: " . config('whatsapp-vendor-concierge.queue.jobs.send_message', 'whatsapp.send_message') . "\n";

$jobs = DB::table('jobs')->count();
echo "Jobs in table: " . $jobs . "\n";
$failed = DB::table('failed_jobs')->count();
echo "Failed jobs: " . $failed . "\n";

$failedRecent = DB::table('failed_jobs')->orderBy('id', 'desc')->limit(5)->get();
foreach($failedRecent as $f) {
    echo "Failed: $f->id | $f->queue | $f->payload\n";
    echo substr($f->exception, 0, 200) . "\n\n";
}
