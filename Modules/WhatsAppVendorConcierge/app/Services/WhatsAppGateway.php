<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Client\Response;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMedia;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;

class WhatsAppGateway
{
    protected Client $client;
    protected string $baseUrl;
    protected string $phoneNumberId;
    protected string $accessToken;
    protected string $apiVersion;

    public function __construct()
    {
        $this->apiVersion = config('whatsapp-vendor-concierge.api.version', 'v21.0');
        $this->baseUrl = config('whatsapp-vendor-concierge.api.base_url', 'https://graph.facebook.com/') . $this->apiVersion;
        $this->phoneNumberId = config('whatsapp-vendor-concierge.api.phone_number_id');
        $this->accessToken = config('whatsapp-vendor-concierge.api.access_token');

        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    /**
     * Validate webhook signature from incoming request.
     */
    public function validateWebhookSignature(\Illuminate\Http\Request $request): bool
    {
        $signature = $request->header(config('whatsapp-vendor-concierge.webhook.signature_header', 'X-Hub-Signature-256'));
        $appSecret = config('whatsapp-vendor-concierge.api.app_secret');

        if (!$signature || !$appSecret) {
            return false;
        }

        if (!str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $expectedHash = hash_hmac('sha256', $request->getContent(), $appSecret);
        $providedHash = substr($signature, 7);

        return hash_equals($expectedHash, $providedHash);
    }

    /**
     * Send a text message
     */
    public function sendTextMessage(string $to, string $body, ?string $previewUrl = null): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => [
                'body' => $body,
                'preview_url' => $previewUrl !== null,
            ],
        ];

        return $this->sendMessage($payload);
    }

    /**
     * Send an interactive button message
     */
    public function sendButtonMessage(string $to, string $body, array $buttons, ?string $header = null, ?string $footer = null): array
    {
        $actionButtons = [];
        $slicedButtons = array_slice($buttons, 0, 3);
        foreach ($slicedButtons as $index => $button) {
            $title = (string) ($button['title'] ?? 'Button');
            if (mb_strlen($title) > 20) {
                $title = mb_substr($title, 0, 20);
            }
            $actionButtons[] = [
                'type' => 'reply',
                'reply' => [
                    'id' => (string) ($button['id'] ?? "btn_{$index}"),
                    'title' => $title,
                ],
            ];
        }

        $interactive = [
            'type' => 'button',
            'body' => ['text' => $body],
            'action' => ['buttons' => $actionButtons],
        ];

        if (!empty($header)) {
            $interactive['header'] = ['type' => 'text', 'text' => $header];
        }

        if (!empty($footer)) {
            $interactive['footer'] = ['text' => $footer];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'interactive',
            'interactive' => $interactive,
        ];

        return $this->sendMessage($payload);
    }

    /**
     * Send an interactive list message
     */
    public function sendListMessage(string $to, string $body, array $sections, ?string $header = null, ?string $footer = null, string $buttonText = 'Select'): array
    {
        $sanitizedSections = [];
        foreach ($sections as $section) {
            $secTitle = (string) ($section['title'] ?? 'Options');
            if (mb_strlen($secTitle) > 24) {
                $secTitle = mb_substr($secTitle, 0, 24);
            }
            $rows = [];
            foreach (($section['rows'] ?? []) as $rIndex => $row) {
                $rowTitle = (string) ($row['title'] ?? "Option {$rIndex}");
                if (mb_strlen($rowTitle) > 24) {
                    $rowTitle = mb_substr($rowTitle, 0, 24);
                }
                $r = [
                    'id' => (string) ($row['id'] ?? "row_{$rIndex}"),
                    'title' => $rowTitle,
                ];
                if (!empty($row['description'])) {
                    $r['description'] = mb_substr((string) $row['description'], 0, 72);
                }
                $rows[] = $r;
            }
            $sanitizedSections[] = [
                'title' => $secTitle,
                'rows' => array_slice($rows, 0, 10),
            ];
        }

        $buttonLabel = mb_substr($buttonText, 0, 20);

        $interactive = [
            'type' => 'list',
            'body' => ['text' => $body],
            'action' => [
                'button' => $buttonLabel,
                'sections' => array_slice($sanitizedSections, 0, 10),
            ],
        ];

        if (!empty($header)) {
            $interactive['header'] = ['type' => 'text', 'text' => $header];
        }

        if (!empty($footer)) {
            $interactive['footer'] = ['text' => $footer];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'interactive',
            'interactive' => $interactive,
        ];

        return $this->sendMessage($payload);
    }

    /**
     * Send a template message
     */
    public function sendTemplateMessage(string $to, string $templateName, array $components = [], string $language = 'en'): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $language],
                'components' => $components,
            ],
        ];

        return $this->sendMessage($payload);
    }

    /**
     * Send a media message (image, document, video, audio)
     */
    public function sendMediaMessage(string $to, string $type, string $mediaId, ?string $caption = null, ?string $filename = null): array
    {
        $mediaPayload = [
            'id' => $mediaId,
        ];

        if ($caption) {
            $mediaPayload['caption'] = $caption;
        }

        if ($filename && in_array($type, ['document'])) {
            $mediaPayload['filename'] = $filename;
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => $type,
            $type => $mediaPayload,
        ];

        return $this->sendMessage($payload);
    }

    /**
     * Send a location message
     */
    public function sendLocationMessage(string $to, float $latitude, float $longitude, string $name, ?string $address = null): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'location',
            'location' => [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'name' => $name,
                'address' => $address,
            ],
        ];

        return $this->sendMessage($payload);
    }

    /**
     * Send a contacts message
     */
    public function sendContactsMessage(string $to, array $contacts): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'contacts',
            'contacts' => $contacts,
        ];

        return $this->sendMessage($payload);
    }

    /**
     * Send a reaction message
     */
    public function sendReaction(string $to, string $messageId, string $emoji): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'reaction',
            'reaction' => [
                'message_id' => $messageId,
                'emoji' => $emoji,
            ],
        ];

        return $this->sendMessage($payload);
    }

    /**
     * Mark message as read
     */
    public function markAsRead(string $messageId): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $messageId,
        ];

        try {
            $response = $this->client->post("/{$this->phoneNumberId}/messages", ['json' => $payload]);
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            Log::error('Failed to mark message as read', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Generic message sending
     */
    protected function sendMessage(array $payload): array
    {
        try {
            $response = $this->client->post("/{$this->phoneNumberId}/messages", ['json' => $payload]);
            $result = json_decode($response->getBody()->getContents(), true);

            Log::info('WhatsApp message sent', [
                'to' => $payload['to'] ?? null,
                'type' => $payload['type'] ?? null,
                'message_id' => $result['messages'][0]['id'] ?? null,
            ]);

            return $result;
        } catch (GuzzleException $e) {
            $errorBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : null;

            Log::error('WhatsApp message send failed', [
                'payload' => $payload,
                'error' => $e->getMessage(),
                'response' => $errorBody,
            ]);

            return [
                'error' => $e->getMessage(),
                'response' => $errorBody ? json_decode($errorBody, true) : null,
            ];
        }
    }

    /**
     * Upload media to WhatsApp
     */
    public function uploadMedia(string $filePath, string $mimeType): array
    {
        try {
            $response = $this->client->post("/{$this->phoneNumberId}/media", [
                'multipart' => [
                    [
                        'name' => 'file',
                        'contents' => fopen($filePath, 'r'),
                        'filename' => basename($filePath),
                    ],
                    [
                        'name' => 'type',
                        'contents' => $mimeType,
                    ],
                    [
                        'name' => 'messaging_product',
                        'contents' => 'whatsapp',
                    ],
                ],
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            Log::info('WhatsApp media uploaded', [
                'media_id' => $result['id'] ?? null,
                'file' => basename($filePath),
            ]);

            return $result;
        } catch (GuzzleException $e) {
            $errorBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : null;

            Log::error('WhatsApp media upload failed', [
                'file' => $filePath,
                'error' => $e->getMessage(),
                'response' => $errorBody,
            ]);

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get media URL from Meta (for downloading)
     */
    public function getMediaUrl(string $mediaId): array
    {
        try {
            $response = $this->client->get("/{$mediaId}");
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            $errorBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : null;

            Log::error('WhatsApp get media URL failed', [
                'media_id' => $mediaId,
                'error' => $e->getMessage(),
                'response' => $errorBody,
            ]);

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Download media from Meta URL
     */
    public function downloadMedia(string $mediaUrl, string $mediaId): ?string
    {
        try {
            $response = $this->client->get($mediaUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                ],
                'sink' => storage_path("app/{$mediaId}.tmp"),
            ]);

            $tempPath = storage_path("app/{$mediaId}.tmp");

            if (file_exists($tempPath)) {
                // Move to permanent storage
                $permanentPath = "whatsapp/media/{$mediaId}";
                Storage::disk(config('whatsapp-vendor-concierge.media.storage_disk', 'public'))
                    ->put($permanentPath, file_get_contents($tempPath));

                unlink($tempPath);

                return $permanentPath;
            }

            return null;
        } catch (GuzzleException $e) {
            Log::error('WhatsApp media download failed', [
                'media_url' => $mediaUrl,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Create a WhatsApp Flow
     */
    public function createFlow(array $flowData): array
    {
        try {
            $response = $this->client->post("/{$this->phoneNumberId}/flows", ['json' => $flowData]);
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            $errorBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : null;

            Log::error('WhatsApp Flow creation failed', [
                'error' => $e->getMessage(),
                'response' => $errorBody,
            ]);

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Update a WhatsApp Flow
     */
    public function updateFlow(string $flowId, array $flowData): array
    {
        try {
            $response = $this->client->post("/{$flowId}", ['json' => $flowData]);
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            $errorBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : null;

            Log::error('WhatsApp Flow update failed', [
                'flow_id' => $flowId,
                'error' => $e->getMessage(),
                'response' => $errorBody,
            ]);

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Publish a WhatsApp Flow
     */
    public function publishFlow(string $flowId): array
    {
        try {
            $response = $this->client->post("/{$flowId}/publish");
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            $errorBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : null;

            Log::error('WhatsApp Flow publish failed', [
                'flow_id' => $flowId,
                'error' => $e->getMessage(),
                'response' => $errorBody,
            ]);

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get Flow details
     */
    public function getFlow(string $flowId): array
    {
        try {
            $response = $this->client->get("/{$flowId}");
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            $errorBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : null;

            Log::error('WhatsApp Flow get failed', [
                'flow_id' => $flowId,
                'error' => $e->getMessage(),
                'response' => $errorBody,
            ]);

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Send a Flow message
     */
    public function sendFlowMessage(string $to, string $flowId, string $screen = 'SCREEN_1', array $data = []): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'flow',
                'header' => [
                    'type' => 'text',
                    'text' => 'MyTijaara Vendor Onboarding',
                ],
                'body' => [
                    'text' => 'Please complete the form below to continue your vendor registration.',
                ],
                'footer' => [
                    'text' => 'Powered by MyTijaara',
                ],
                'action' => [
                    'name' => 'flow',
                    'parameters' => json_encode([
                        'flow_message_version' => '5.0',
                        'flow_token' => base64_encode(json_encode(['screen' => $screen, 'data' => $data])),
                        'flow_id' => $flowId,
                        'flow_cta' => 'Continue',
                        'flow_action' => 'navigate',
                        'flow_action_payload' => json_encode(['screen' => $screen]),
                    ]),
                ],
            ],
        ];

        return $this->sendMessage($payload);
    }

    /**
     * Get business profile
     */
    public function getBusinessProfile(): array
    {
        try {
            $response = $this->client->get("/{$this->phoneNumberId}/whatsapp_business_profile", [
                'query' => ['fields' => 'about,address,description,email,profile_picture_url,websites,vertical'],
            ]);
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            $errorBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : null;

            Log::error('WhatsApp get business profile failed', [
                'error' => $e->getMessage(),
                'response' => $errorBody,
            ]);

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Update business profile
     */
    public function updateBusinessProfile(array $data): array
    {
        try {
            $response = $this->client->post("/{$this->phoneNumberId}/whatsapp_business_profile", ['json' => $data]);
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            $errorBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : null;

            Log::error('WhatsApp update business profile failed', [
                'error' => $e->getMessage(),
                'response' => $errorBody,
            ]);

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Verify webhook subscription
     */
    public function verifyWebhook(string $verifyToken, string $challenge): ?string
    {
        $expectedToken = config('whatsapp-vendor-concierge.webhook.verify_token');

        if ($verifyToken === $expectedToken) {
            return $challenge;
        }

        return null;
    }

    /**
     * Get phone number info
     */
    public function getPhoneNumberInfo(): array
    {
        try {
            $response = $this->client->get("/{$this->phoneNumberId}");
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            $errorBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : null;

            Log::error('WhatsApp get phone number info failed', [
                'error' => $e->getMessage(),
                'response' => $errorBody,
            ]);

            return ['error' => $e->getMessage()];
        }
    }
}