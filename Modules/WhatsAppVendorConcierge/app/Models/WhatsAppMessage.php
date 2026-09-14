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

    public function getReadAtAttribute($value)
    {
        if (!$value) {
            return null;
        }
        $ts = $this->metadata['receipt_timestamps']['read'] ?? null;
        if ($ts !== null) {
            return \Carbon\Carbon::createFromTimestampUTC((int) $ts);
        }
        return \Carbon\Carbon::parse($value);
    }

    public function getDeliveredAtAttribute($value)
    {
        if (!$value) {
            return null;
        }
        $ts = $this->metadata['receipt_timestamps']['delivered'] ?? null;
        if ($ts !== null) {
            return \Carbon\Carbon::createFromTimestampUTC((int) $ts);
        }
        return \Carbon\Carbon::parse($value);
    }

    public function getSentAtAttribute($value)
    {
        if (!$value) {
            return null;
        }
        $ts = $this->metadata['receipt_timestamps']['sent'] ?? null;
        if ($ts !== null) {
            return \Carbon\Carbon::createFromTimestampUTC((int) $ts);
        }
        return \Carbon\Carbon::parse($value);
    }

    /**
     * Log an inbound message.
     */
    public static function logInbound(int $conversationId, array $messageData, array $metaValue): self
    {
        $message = \Modules\WhatsAppVendorConcierge\app\Services\InboundPrivacy::redact(
            $messageData, WhatsAppConversation::find($conversationId)
        );
        $content = [
            'text' => $message['text']['body'] ?? null,
            'button' => $message['button'] ?? null,
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
        if ($type === 'interactive') {
            $type = isset($message['interactive']['button_reply']) ? 'interactive_button'
                : (isset($message['interactive']['list_reply']) ? 'interactive_list' : 'interactive_flow');
        }
        $mediaId = null;

        if (in_array($type, ['image', 'document', 'video', 'audio'])) {
            // Local foreign key is assigned only after the media row is created.
            $mediaId = null;
        }

        // Idempotency: check if message already exists
        $existing = self::where('whatsapp_message_id', $message['id'])->first();
        if ($existing) {
            return $existing;
        }

        return self::create([
            'conversation_id' => $conversationId,
            'whatsapp_message_id' => $message['id'],
            'direction' => 'inbound',
            'type' => $type,
            'content' => array_filter($content),
            'raw_text' => $message['text']['body'] ?? $message['button']['text'] ?? ($message['interactive']['button_reply']['title'] ?? ($message['interactive']['list_reply']['title'] ?? ($message['interactive']['nfm_reply']['response_json'] ?? null))),
            'media_id' => $mediaId,
            'status' => 'delivered',
            'metadata' => [
                'from' => $message['from'] ?? null,
                'timestamp' => $message['timestamp'] ?? null,
                'phone_number_id' => $metaValue['metadata']['phone_number_id'] ?? null,
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
            default => null,
        };

        $this->update($update);
    }

    public function applyReceipt(array $receipt): void
    {
        $status = $receipt['status'];
        $at = \Carbon\Carbon::createFromTimestamp((int) $receipt['timestamp'], config('app.timezone'));
        $rank = ['pending' => 0, 'failed' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3];
        $metadata = $this->metadata ?? [];
        $timestamps = $metadata['receipt_timestamps'] ?? [];
        $previous = $timestamps[$status] ?? null;
        $timestamp = (int) $receipt['timestamp'];
        // Identical receipt deliveries and older replayed receipts must not
        // overwrite a newer event timestamp for the same delivery state.
        if ($previous !== null && $timestamp <= $previous) {
            return;
        }
        $timestamps[$status] = $timestamp;
        $update = ['metadata' => array_merge($metadata, ['receipt_timestamps' => $timestamps])];
        if ($status === 'failed') {
            $update['error'] = ['codes' => $receipt['error_codes'] ?? []];
            if (($rank[$this->status] ?? 0) < 2) {
                $update['status'] = 'failed';
            }
        } else {
            $update[$status . '_at'] = $at;
            if (($rank[$status] ?? 0) > ($rank[$this->status] ?? 0)) {
                $update['status'] = $status;
            }
        }
        $this->update($update);
    }
}
