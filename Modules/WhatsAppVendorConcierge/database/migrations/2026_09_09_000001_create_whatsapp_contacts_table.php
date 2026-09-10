<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('whatsapp_id', 191)->unique()->comment('Meta WhatsApp user ID (wa_id)');
            $table->string('phone_number', 191)->unique()->comment('E.164 format phone number');
            $table->string('display_name')->nullable()->comment('WhatsApp profile name');
            $table->string('profile_picture_url')->nullable();
            $table->unsignedBigInteger('vendor_id')->nullable()->comment('Linked Vendor (if registered)');
            $table->unsignedBigInteger('user_id')->nullable()->comment('Linked Customer User (if customer)');
            $table->enum('contact_type', ['unknown', 'customer', 'vendor_applicant', 'vendor', 'rejected_applicant'])->default('unknown');
            $table->json('metadata')->nullable()->comment('Additional WhatsApp profile data');
            $table->timestamp('first_interaction_at')->nullable();
            $table->timestamp('last_interaction_at')->nullable();
            $table->timestamp('opted_in_at')->nullable()->comment('When user opted into business messaging');
            $table->boolean('is_blocked')->default(false);
            $table->timestamps();

            $table->foreign('vendor_id')->references('id')->on('vendors')->onDelete('set null');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->index(['contact_type', 'last_interaction_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_contacts');
    }
};