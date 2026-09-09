<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_media', function (Blueprint $table) {
            $table->id();
            $table->string('whatsapp_media_id')->unique()->comment('Meta media ID');
            $table->string('mime_type');
            $table->string('sha256')->nullable()->comment('File hash for deduplication');
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('file_path')->nullable()->comment('Local storage path after download');
            $table->string('storage_disk')->default('public');
            $table->enum('status', [
                'pending_download',
                'downloaded',
                'processing',
                'processed',
                'failed',
                'cleaned_up',
            ])->default('pending_download');
            $table->json('metadata')->nullable()->comment('Meta media metadata');
            $table->json('ocr_result')->nullable()->comment('OCR extraction result');
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamp('expires_at')->nullable()->comment('Meta URL expiry');
            $table->timestamp('cleanup_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'cleanup_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_media');
    }
};