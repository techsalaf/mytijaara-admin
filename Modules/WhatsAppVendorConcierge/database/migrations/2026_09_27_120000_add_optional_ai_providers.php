<?php

use Illuminate\Database\Migrations\Migration;
use Modules\WhatsAppVendorConcierge\database\seeders\AdditionalAiProviderSeeder;

return new class extends Migration {
    public function up(): void
    {
        (new AdditionalAiProviderSeeder)->run();
    }

    public function down(): void
    {
        // Preserve definitions, encrypted credentials and usage history on rollback.
    }
};
