<?php

namespace Modules\WhatsAppVendorConcierge\app\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppMessage extends Model
{
    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'conversation_id',
        'whatsapp_message_id',
        'direction',
        'type',
        'content',
        'raw_text',
        'media_id',
        'status',
        'metadata',
        'error',
        'sent_at',
        'delivered_at',
        'read_at',
    ];

    protected $casts = [
        'content' => 'array',
        'metadata' => 'array',
        'error' => 'array',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConversation::class, 'conversation_id');
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(WhatsAppMedia::class, 'media_id');
    }

    /**
     * Log an inbound message.
     */
    public static function logInbound(int $conversationId, array $messageData, array $metaValue): self
    {
        $message = $messageData;
        $content = [
            'text' => $message['text']['body'] ?? null,
            'interactive' => $message['interactive'] ?? null,
            'location' => $message['location'] ?? null,
            'image' => $message['image'] ?? null,
            'document' => $message['document'] ?? null,
            'video' => $message['video'] ?? null,
            'audio' => $message['audio'] ?? null,
            'contacts' => $message['contacts'] ?? null,
            'reaction' => $message['reaction'] ?? null,
        ];

        $type = $message['type'] ?? 'text';
        $mediaId = null;

        if (in_array($type, ['image', 'document', 'video', 'audio'])) {
            $mediaId = $message[$type]['id'] ?? null;
        }

        return self::create([
            'conversation_id' => $conversationId,
            'whatsapp_message_id' => $message['id'],
            'direction' => 'inbound',
            'type' => $type,
            'content' => array_filter($content),
            'raw_text' => $message['text']['body'] ?? ($message['interactive']['button_reply']['title'] ?? ($message['interactive']['list_reply']['title'] ?? ($message['interactive']['nfm_reply']['response_json'] ?? null))),
            'media_id' => $mediaId,
            'status' => 'delivered',
            'metadata' => [
                'from' => $message['from'] ?? null,
                'timestamp' => $message['timestamp'] ?? null,
                'meta_value' => $metaValue,
            ],
            'delivered_at' => now(),
        ]);
    }

    /**
     * Log an outbound message.
     */
    public static function logOutbound(int $conversationId, array $payload, array $response): self
    {
        $messageId = $response['messages'][0]['id'] ?? null;

        return self::create([
            'conversation_id' => $conversationId,
            'whatsapp_message_id' => $messageId,
            'direction' => 'outbound',
            'type' => $payload['type'] ?? 'text',
            'content' => $payload,
            'raw_text' => $payload['text']['body'] ?? ($payload['interactive']['body']['text'] ?? null),
            'status' => $messageId ? 'sent' : 'failed',
            'metadata' => ['response' => $response],
            'error' => $messageId ? null : $response,
            'sent_at' => $messageId ? now() : null,
        ]);
    }

    /**
     * Update message status from webhook.
     */
    public function updateStatus(string $status, array $metadata = []): void
    {
        $update = [
            'status' => $status,
            'metadata' => array_merge($this->metadata ?? [], $metadata),
        ];

        match ($status) {
            'sent' => $update['sent_at'] = now(),
            'delivered' => $update['delivered_at'] = now(),
            'read' => $update['read_at'] = now(),
        };

        $this->update($update);
    }
}