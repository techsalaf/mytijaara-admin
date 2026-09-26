<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_product_drafts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('conversation_id')->index();
            $t->unsignedBigInteger('contact_id');
            $t->unsignedBigInteger('store_id')->index();
            $t->unsignedBigInteger('module_id');
            $t->string('status')->default('active')->index();
            $t->string('step')->default('image');
            $t->json('data');
            $t->json('sources');
            $t->json('vision')->nullable();
            $t->json('errors')->nullable();
            $t->json('processed_messages')->nullable();
            $t->unsignedInteger('stalled_turns')->default(0);
            $t->boolean('needs_attention')->default(false);
            $t->unsignedBigInteger('item_id')->nullable()->unique();
            $t->timestamps();
        });
        Schema::create('whatsapp_taxonomy_keys', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('module_id');
            $t->string('stable_key');
            $t->unsignedBigInteger('category_id');
            $t->unique(['module_id', 'stable_key']);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_taxonomy_keys');
        Schema::dropIfExists('whatsapp_product_drafts');
    }
};
