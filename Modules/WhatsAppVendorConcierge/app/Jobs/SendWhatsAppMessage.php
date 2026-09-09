<?php

namespace Modules\WhatsAppVendorConcierge\app\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;

class SendWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;
    public int $timeout = 30;

    public function __construct(
        public string $to,
        public string $type,
        public array $payload,
        public ?int $conversationId = null
    ) {}

    public function handle(WhatsAppGateway $gateway): void
    {
        try {
            $result = match ($this->type) {
                'text' => $gateway->sendTextMessage($this->to, $this->payload['body'] ?? '', $this->payload['preview_url'] ?? null),
                'button' => $gateway->sendButtonMessage(
                    $this->to,
                    $this->payload['body'] ?? '',
                    $this->payload['buttons'] ?? [],
                    $this->payload['header'] ?? null,
                    $this->payload['footer'] ?? null
                ),
                'list' => $gateway->sendListMessage(
                    $this->to,
                    $this->payload['body'] ?? '',
                    $this->payload['sections'] ?? [],
                    $this->payload['header'] ?? null,
                    $this->payload['footer'] ?? null,
                    $this->payload['button_text'] ?? 'Select'
                ),
                'template' => $gateway->sendTemplateMessage(
                    $this->to,
                    $this->payload['template_name'] ?? '',
                    $this->payload['components'] ?? [],
                    $this->payload['language'] ?? 'en'
                ),
                'image' => $gateway->sendMediaMessage($this->to, 'image', $this->payload['media_id'], $this->payload['caption'] ?? null),
                'document' => $gateway->sendMediaMessage($this->to, 'document', $this->payload['media_id'], $this->payload['caption'] ?? null, $this->payload['filename'] ?? null),
                'location' => $gateway->sendLocationMessage($this->to, $this->payload['latitude'], $this->payload['longitude'], $this->payload['name'], $this->payload['address'] ?? null),
                default => ['error' => 'Unknown message type: ' . $this->type],
            };

            // Log outbound message if conversation ID provided
            if ($this->conversationId && isset($result['messages'][0]['id'])) {
                $conversation = WhatsAppConversation::find($this->conversationId);
                if ($conversation) {
                    \Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage::logOutbound($this->conversationId, $this->payload, $result);
                }
            }

            Log::info('WhatsApp message sent via queue', [
                'to' => $this->to,
                'type' => $this->type,
                'message_id' => $result['messages'][0]['id'] ?? null,
                'success' => !isset($result['error']),
            ]);

        } catch (\Throwable $e) {
            Log::error('SendWhatsAppMessage job failed', [
                'to' => $this->to,
                'type' => $this->type,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}