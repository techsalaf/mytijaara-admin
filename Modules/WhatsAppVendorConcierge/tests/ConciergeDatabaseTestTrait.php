<?php

namespace Modules\WhatsAppVendorConcierge\tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait ConciergeDatabaseTestTrait
{
    protected function setupConciergeTables(): void
    {
        if (config('database.default') === 'sqlite') {
            foreach (['vendors', 'stores', 'users', 'admins'] as $name) {
                if (!Schema::hasTable($name)) {
                    Schema::create($name, fn (Blueprint $table) => $table->id());
                }
            }
            if (!Schema::hasTable('business_settings')) {
                Schema::create('business_settings', function (Blueprint $table) {
                    $table->id();
                    $table->string('key');
                    $table->text('value')->nullable();
                    $table->timestamps();
                });
            }
            foreach (glob(base_path('Modules/WhatsAppVendorConcierge/database/migrations/*.php')) as $migration) {
                $class = require $migration;
                if (is_object($class) && method_exists($class, 'up')) {
                    try {
                        $class->up();
                    } catch (\Throwable) {
                        // Ignore if table or index already exists in shared in-memory connection
                    }
                }
            }
        }
    }
}
