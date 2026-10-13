<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== CHECKING AVAILABLE DATABASES ===\n";
try {
    $dbs = DB::select('SHOW DATABASES');
    foreach ($dbs as $db) {
        $name = array_values((array)$db)[0];
        echo "  - {$name}\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Check if mytijaaracp_waitlist exists
echo "\n=== CHECKING WAITLIST DATABASE ENTRIES ===\n";
try {
    $count = DB::table('information_schema.schemata')->where('schema_name', 'mytijaaracp_waitlist')->count();
    if ($count > 0) {
        echo "Found mytijaaracp_waitlist database!\n";
        $entries = DB::select('SELECT role, status, verified, count(*) as total FROM mytijaaracp_waitlist.waitlist_entries GROUP BY role, status, verified');
        foreach ($entries as $row) {
            echo "  Role: {$row->role} | Status: {$row->status} | Verified: {$row->verified} => Total: {$row->total}\n";
        }
        $totalWaitlist = DB::table('mytijaaracp_waitlist.waitlist_entries')->count();
        echo "Total waitlist entries: {$totalWaitlist}\n";
    } else {
        echo "Database mytijaaracp_waitlist not found in information_schema.\n";
    }
} catch (\Exception $e) {
    echo "Waitlist DB Error: " . $e->getMessage() . "\n";
}
