<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id')->index();
            $table->string('whatsapp_message_id', 191)->unique()->comment('Meta message ID for idempotency');
            $table->enum('direction', ['inbound', 'outbound']);
            $table->enum('type', [
                'text',
                'image',
                'document',
                'audio',
                'video',
                'location',
                'contacts',
                'interactive_button',
                'interactive_list',
                'interactive_flow',
                'template',
                'reaction',
                'system',
            ]);
            $table->json('content')->nullable()->comment('Structured message content');
            $table->text('raw_text')->nullable()->comment('Plain text for search');
            $table->unsignedBigInteger('media_id')->nullable()->index();
            $table->enum('status', [
                'pending',
                'sent',
                'delivered',
                'read',
                'failed',
                'deleted',
            ])->default('pending');
            $table->json('metadata')->nullable()->comment('Additional Meta data');
            $table->json('error')->nullable()->comment('Error details if failed');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->foreign('conversation_id')->references('id')->on('whatsapp_conversations')->onDelete('cascade');
            $table->index(['conversation_id', 'created_at']);
            $table->index(['whatsapp_message_id', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};