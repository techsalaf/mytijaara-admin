<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use App\CentralLogics\Helpers;
use App\Models\BusinessSetting;
use App\Models\Module;
use App\Models\Store;
use App\Models\Vendor;
use App\Models\Zone;
use App\Models\SubscriptionPackage;
use App\Models\StoreSubscription;
use App\Models\StoreSchedule;
use App\Mail\VendorSelfRegistration;
use App\Mail\StoreRegistration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingEvent;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Jobs\SendWhatsAppMessage;

class VendorOnboardingService
{
    public function __construct(
        protected \App\CentralLogics\StoreLogic $storeLogic
    ) {}

    /**
     * Process onboarding step based on current state.
     */
    public function processStep(
        WhatsAppConversation $conversation,
        WhatsAppContact $contact,
        \Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage $message,
        \Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway $gateway
    ): void {
        $step = $conversation->current_step ?? 'welcome';
        $session = OnboardingSession::find($conversation->onboarding_session_id);

        // Special handling when currently on review_submit
        if ($step === 'review_submit') {
            $rawText = strtolower(trim((string) ($message->raw_text ?? ($message->content['text'] ?? ''))));

            if (in_array($rawText, ['submit', 'yes', 'confirm', 'proceed', 'done', 'ok', 'okay', 'correct'])) {
                $this->submitApplication($conversation, $contact, $session, $gateway);
                return;
            }

            if (in_array($rawText, ['cancel', 'stop', 'abort'])) {
                $conversationManager = app(\Modules\WhatsAppVendorConcierge\app\Services\ConversationManager::class);
                $conversationManager->handleCancelApplication($conversation, $contact, $gateway);
                return;
            }

            if (in_array($rawText, ['edit', 'change', 'modify', 'update'])) {
                $conversationManager = app(\Modules\WhatsAppVendorConcierge\app\Services\ConversationManager::class);
                $conversationManager->handleEditApplication($conversation, $contact, $gateway);
                return;
            }

            $editKeywordMap = [
                'business' => 'business_basics',
                'shop' => 'business_basics',
                'module' => 'module_selection',
                'category' => 'module_selection',
                'location' => 'location',
                'address' => 'location',
                'pin' => 'location',
                'zone' => 'zone_selection',
                'hour' => 'operating_hours',
                'hours' => 'operating_hours',
                'operating' => 'operating_hours',
                'schedule' => 'operating_hours',
                'delivery' => 'delivery_time',
                'time' => 'delivery_time',
                'owner' => 'owner_info',
                'name' => 'owner_info',
                'email' => 'contact_info',
                'phone' => 'contact_info',
                'contact' => 'contact_info',
                'password' => 'account_password',
                'pass' => 'account_password',
                'logo' => 'store_branding',
                'branding' => 'store_branding',
                'photo' => 'store_branding',
                'image' => 'store_branding',
                'plan' => 'business_plan',
                'subscription' => 'business_plan',
                'commission' => 'business_plan',
                'terms' => 'terms_acceptance',
                'privacy' => 'privacy_acceptance',
                'policy' => 'privacy_acceptance',
                'kyc' => 'kyc_documents',
                'tin' => 'kyc_documents',
                'cac' => 'kyc_documents',
                'nin' => 'kyc_documents',
                'document' => 'kyc_documents',
                'documents' => 'kyc_documents',
                'certificate' => 'kyc_documents',
            ];

            foreach ($editKeywordMap as $keyword => $targetStep) {
                if (str_contains($rawText, $keyword)) {
                    $conversationManager = app(\Modules\WhatsAppVendorConcierge\app\Services\ConversationManager::class);
                    $conversationManager->handleEditSection($conversation, $contact, $targetStep, $gateway);
                    return;
                }
            }

            // If user typed anything else, re-send the review summary with action buttons
            $this->sendStepPrompt($conversation, $contact, 'review_submit', $gateway);
            return;
        }

        $data = $this->extractStepData($message, $step, $contact);

        // Validate step data
        $validation = $this->validateStep($step, $data);

        if (!$validation['valid']) {
            $this->sendValidationErrors($conversation, $contact, $validation['errors'], $gateway);

            $sanitizedData = $data;
            unset($sanitizedData['password']);

            OnboardingEvent::log(
                $conversation->onboarding_session_id,
                $contact->id,
                'step_failed',
                $step,
                ['data' => $sanitizedData, 'errors' => $validation['errors']]
            );
            return;
        }

        // Scrub plaintext password before persisting to session
        if (isset($data['password'])) {
            unset($data['password']);
        }

        // Save data to session
        $session = OnboardingSession::find($conversation->onboarding_session_id);
        if ($session) {
            $session->updateData($data);
            $session->update(['current_step' => $step]);
        }

        // Log successful step
        OnboardingEvent::log(
            $conversation->onboarding_session_id,
            $contact->id,
            'step_completed',
            $step,
            ['data' => $data]
        );

        // Advance to next step or complete
        $this->advanceToNextStep($conversation, $contact, $session, $gateway);
    }

    /**
     * Extract data from message based on step.
     */
    protected function extractStepData(\Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage $message, string $step, ?WhatsAppContact $contact = null): array
    {
        $content = $message->content ?? [];
        $type = $message->type ?? 'text';
        $rawText = trim((string) ($message->raw_text ?? ($content['text'] ?? '')));

        return match ($step) {
            'business_basics' => [
                'business_name' => $rawText ?: ($content['text'] ?? null),
                'business_description' => $rawText ?: ($content['text'] ?? null),
            ],
            'module_selection' => (function () use ($content, $rawText) {
                $modId = $content['interactive']['list_reply']['id']
                    ?? $content['interactive']['button_reply']['id']
                    ?? null;

                if ($modId && str_starts_with($modId, 'mod_')) {
                    $modId = str_replace('mod_', '', $modId);
                }

                if (!$modId && $rawText !== '') {
                    $matched = Module::active()->notParcel()
                        ->where(function ($q) use ($rawText) {
                            $q->where('module_name', 'LIKE', "%{$rawText}%")
                              ->orWhere('id', $rawText);
                        })->first();
                    if ($matched) {
                        $modId = (string) $matched->id;
                    }
                }

                if (!$modId) {
                    $firstMod = Module::active()->notParcel()->first();
                    $modId = $firstMod?->id;
                }

                $module = $modId ? Module::find($modId) : null;
                return [
                    'module_id' => $module?->id ?? ($modId ? (int)$modId : null),
                    'module_name' => $module?->module_name ?? 'Business Module',
                ];
            })(),
            'category_selection' => (function () use ($content, $rawText) {
                $catId = $content['interactive']['list_reply']['id']
                    ?? $content['interactive']['button_reply']['id']
                    ?? null;
                $catName = $content['interactive']['list_reply']['title']
                    ?? $content['interactive']['button_reply']['title']
                    ?? null;

                if (!$catId && $rawText !== '') {
                    $matched = \App\Models\Category::where('status', 1)
                        ->where(function ($query) use ($rawText) {
                            $query->where('name', 'LIKE', "%{$rawText}%")
                                  ->orWhere('id', $rawText);
                        })
                        ->first();

                    if ($matched) {
                        $catId = (string) $matched->id;
                        $catName = $matched->name;
                    }
                }

                $category = $catId ? \App\Models\Category::find($catId) : null;
                $moduleId = $category?->module_id ?? null;

                return [
                    'category_id' => $catId,
                    'category_name' => $catName,
                    'module_id' => $moduleId,
                ];
            })(),
            'location' => (function () use ($content, $rawText) {
                $loc = $this->resolveLocation($content, $rawText);
                if (!empty($loc['latitude']) && !empty($loc['longitude'])) {
                    try {
                        $detectedZone = Zone::whereContains('coordinates',
                            new \MatanYadaev\EloquentSpatial\Objects\Point(
                                (float)$loc['latitude'],
                                (float)$loc['longitude'],
                                4326
                            ))->first();
                        if ($detectedZone) {
                            $loc['zone_id'] = $detectedZone->id;
                            $loc['zone_name'] = $detectedZone->name;
                            $loc['zone_detected'] = true;
                        }
                    } catch (\Throwable $e) {
                        Log::info('Zone detection error: ' . $e->getMessage());
                    }
                }
                return $loc;
            })(),
            'zone_selection' => (function () use ($content, $rawText) {
                $btnId = $content['interactive']['button_reply']['id']
                    ?? $content['interactive']['list_reply']['id']
                    ?? null;
                $raw = trim($btnId ?: $rawText);

                if ($btnId && str_starts_with($btnId, 'zone_')) {
                    $zoneId = (int) str_replace('zone_', '', $btnId);
                    $zone = Zone::find($zoneId);
                    return ['zone_id' => $zone?->id ?? $zoneId, 'zone_name' => $zone?->name ?? 'Selected Zone'];
                }

                if (in_array(strtolower($raw), ['confirm', 'yes', 'correct', 'ok', 'okay', '1'])) {
                    $fallbackZone = Zone::active()->first() ?? Zone::first();
                    return [
                        'zone_id' => $fallbackZone?->id ?? 1,
                        'zone_name' => $fallbackZone?->name ?? 'Default Zone',
                    ];
                }

                $matched = Zone::active()
                    ->where(function ($q) use ($raw) {
                        $q->where('name', 'LIKE', "%{$raw}%")
                          ->orWhere('id', $raw);
                    })->first();

                if (!$matched) {
                    $matched = Zone::active()->first() ?? Zone::first();
                }

                return [
                    'zone_id' => $matched?->id ?? 1,
                    'zone_name' => $matched?->name ?? 'Default Zone',
                ];
            })(),
            'operating_hours' => (function () use ($content, $rawText) {
                $btnId = $content['interactive']['button_reply']['id']
                    ?? $content['interactive']['list_reply']['id']
                    ?? null;
                $raw = trim($btnId ?: $rawText);

                if ($btnId === 'hours_standard' || str_contains(strtolower($raw), 'standard')) {
                    $hours = 'Mon - Sat (8:00 AM - 8:00 PM)';
                    return ['operating_hours' => $hours, 'schedule' => $hours, 'schedule_type' => 'standard'];
                }
                if ($btnId === 'hours_everyday' || str_contains(strtolower($raw), 'everyday')) {
                    $hours = 'Mon - Sun (8:00 AM - 10:00 PM)';
                    return ['operating_hours' => $hours, 'schedule' => $hours, 'schedule_type' => 'everyday'];
                }
                if ($btnId === 'hours_247' || str_contains(strtolower($raw), '24/7') || str_contains(strtolower($raw), 'always')) {
                    $hours = '24/7 (Always Open)';
                    return ['operating_hours' => $hours, 'schedule' => $hours, 'schedule_type' => '247'];
                }

                $hours = $rawText ?: 'Mon - Sat (8:00 AM - 8:00 PM)';
                return ['operating_hours' => $hours, 'schedule' => $hours, 'schedule_type' => 'custom'];
            })(),
            'delivery_time' => (function () use ($content, $rawText) {
                $btnId = $content['interactive']['button_reply']['id']
                    ?? $content['interactive']['list_reply']['id']
                    ?? null;
                $raw = trim($btnId ?: $rawText);

                if ($btnId === 'deliv_20_40' || str_contains($raw, '20')) {
                    return ['delivery_time' => '20-40 min'];
                }
                if ($btnId === 'deliv_30_60' || str_contains($raw, '30')) {
                    return ['delivery_time' => '30-60 min'];
                }
                if ($btnId === 'deliv_1_2hr' || str_contains($raw, '1-2')) {
                    return ['delivery_time' => '1-2 hours'];
                }

                return ['delivery_time' => $rawText ?: '20-40 min'];
            })(),
            'owner_info' => (function () use ($rawText, $content) {
                $text = trim($rawText ?: ($content['text'] ?? ''));
                $parts = preg_split('/\s+/', $text, 2);
                $fName = $parts[0] ?? '';
                $lName = $parts[1] ?? '';

                return [
                    'f_name' => $fName,
                    'l_name' => $lName,
                    'owner_name' => $text,
                ];
            })(),
            'contact_info' => [
                'email' => strtolower(trim($rawText ?: ($content['text'] ?? ''))),
                'phone' => $contact?->phone_number ?? ($content['text'] ?? null),
            ],
            'account_password' => (function () use ($rawText, $contact) {
                $session = $contact
                    ? OnboardingSession::where('contact_id', $contact->id)->where('status', 'started')->latest('last_activity_at')->first()
                    : null;
                $hasPassword = !empty($session?->collected_data['has_password']) || !empty($session?->collected_data['password_hash']);
                $testPassword = trim($rawText);

                return [
                    'has_password' => $hasPassword,
                    'password' => $testPassword,
                    'plaintext_sent' => $testPassword !== '',
                ];
            })(),
            'store_branding' => (function () use ($message, $content, $rawText) {
                $lower = strtolower(trim($rawText));
                // MANDATORY: No skip option allowed!
                if (in_array($lower, ['skip', 'default', 'none', 'later', 'pass'])) {
                    return [
                        'logo_media_id' => null,
                        'has_logo' => false,
                        'skipped_attempt' => true,
                    ];
                }

                $mediaId = $this->extractMediaId($message, $content);

                return [
                    'logo_media_id' => $mediaId,
                    'has_logo' => !empty($mediaId),
                ];
            })(),
            'business_plan' => (function () use ($content, $rawText) {
                $btnId = $content['interactive']['button_reply']['id']
                    ?? $content['interactive']['list_reply']['id']
                    ?? null;
                $lower = strtolower(trim($btnId ?: $rawText));

                if (str_contains($lower, 'subscri') || $btnId === 'plan_subscription') {
                    return [
                        'business_plan' => 'subscription-base',
                        'plan_name' => 'Subscription Plan',
                    ];
                }

                return [
                    'business_plan' => 'commission-base',
                    'plan_name' => 'Commission-Based',
                ];
            })(),
            'terms_acceptance' => (function () use ($content, $rawText) {
                $btnId = $content['interactive']['button_reply']['id'] ?? null;
                $lower = strtolower(trim($btnId ?: $rawText));

                $isAccepted = $btnId === 'accept_terms'
                    || in_array($lower, ['accept', 'i accept', 'yes', 'agree', 'i agree', 'accept_terms', 'ok', 'okay', '1']);

                return [
                    'terms_accepted' => $isAccepted,
                ];
            })(),
            'privacy_acceptance' => (function () use ($content, $rawText) {
                $btnId = $content['interactive']['button_reply']['id'] ?? null;
                $lower = strtolower(trim($btnId ?: $rawText));

                $isAccepted = $btnId === 'accept_privacy'
                    || in_array($lower, ['accept', 'i accept', 'yes', 'agree', 'i agree', 'accept_privacy', 'ok', 'okay', '1']);

                return [
                    'privacy_accepted' => $isAccepted,
                ];
            })(),
            'kyc_documents' => (function () use ($message, $content, $rawText) {
                $lower = strtolower(trim($rawText));
                if (in_array($lower, ['skip', 'none', 'later', 'na', 'n/a'])) {
                    return [
                        'tin' => null,
                        'cac_number' => null,
                        'nin' => null,
                        'tin_media_id' => null,
                        'kyc_type' => 'skipped',
                        'kyc_skipped' => true,
                    ];
                }

                $mediaId = $this->extractMediaId($message, $content);
                $kycType = 'tin';
                $tin = null;
                $cac = null;
                $nin = null;

                if ($rawText !== '' && $mediaId === null) {
                    $tin = trim($rawText);
                    if (str_starts_with($lower, 'cac:') || str_starts_with($lower, 'rc') || str_starts_with($lower, 'bn')) {
                        $kycType = 'cac';
                        $cac = trim($rawText);
                    } elseif (str_starts_with($lower, 'nin:') || strlen(preg_replace('/\D/', '', $rawText)) === 11) {
                        $kycType = 'nin';
                        $nin = trim($rawText);
                    } else {
                        $kycType = 'tin';
                    }
                }

                return [
                    'tin' => $tin,
                    'cac_number' => $cac,
                    'nin' => $nin,
                    'kyc_type' => $kycType,
                    'tin_media_id' => $mediaId,
                    'kyc_skipped' => false,
                ];
            })(),
            'documents' => (function () use ($message, $content) {
                $mediaId = $this->extractMediaId($message, $content);
                return ['media_id' => $mediaId];
            })(),
            default => [],
        };
    }

    /**
     * Extract media ID from message or content payload.
     */
    protected function extractMediaId(\Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage $message, array $content): ?int
    {
        // 1. If message already has a media record ID
        if (!empty($message->media_id)) {
            $media = \Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMedia::find($message->media_id);
            if ($media) {
                return $media->id;
            }
            $media = \Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMedia::where('whatsapp_media_id', $message->media_id)->first();
            if ($media) {
                return $media->id;
            }
        }

        // 2. Check for uploaded document (PDF, Word, Excel, etc.) or image
        $mediaPayload = $content['document'] ?? ($content['image'] ?? null);
        if (!empty($mediaPayload['id'])) {
            $media = \Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMedia::firstOrCreate(
                ['whatsapp_media_id' => $mediaPayload['id']],
                [
                    'mime_type' => $mediaPayload['mime_type'] ?? ($content['document']['mime_type'] ?? ($content['image']['mime_type'] ?? 'application/octet-stream')),
                    'file_size' => $mediaPayload['file_size'] ?? null,
                    'status' => 'pending_download',
                    'expires_at' => now()->addDays(7),
                ]
            );

            $message->update(['media_id' => $media->id]);
            return $media->id;
        }

        return null;
    }

    /**
     * Resolve location data from message (native location, Google Maps link, or text address).
     */
    protected function resolveLocation(array $content, string $rawText): array
    {
        // 1. Native WhatsApp location message
        if (!empty($content['location']['latitude']) && !empty($content['location']['longitude'])) {
            $lat = (float) $content['location']['latitude'];
            $lng = (float) $content['location']['longitude'];
            $address = !empty($content['location']['address'])
                ? trim($content['location']['address'])
                : (!empty($content['location']['name']) ? trim($content['location']['name']) : null);

            if (empty($address)) {
                $address = $this->reverseGeocode($lat, $lng);
            }

            return [
                'latitude' => $lat,
                'longitude' => $lng,
                'address' => $address ?: "Location at " . round($lat, 5) . ", " . round($lng, 5),
            ];
        }

        $textToParse = trim($rawText ?: ($content['text'] ?? ''));

        if ($textToParse === '') {
            return [
                'latitude' => null,
                'longitude' => null,
                'address' => null,
            ];
        }

        // 2. Google Maps URL or coordinates in text
        if (
            preg_match('/(?:q|ll|query)=([+-]?\d+(?:\.\d+)?),([+-]?\d+(?:\.\d+)?)/i', $textToParse, $matches) ||
            preg_match('/@([+-]?\d+(?:\.\d+)?),([+-]?\d+(?:\.\d+)?)/i', $textToParse, $matches) ||
            preg_match('/^([+-]?\d{1,2}(?:\.\d+)?)\s*,\s*([+-]?\d{1,3}(?:\.\d+)?)$/', $textToParse, $matches)
        ) {
            $lat = (float) $matches[1];
            $lng = (float) $matches[2];
            $address = $this->reverseGeocode($lat, $lng);

            return [
                'latitude' => $lat,
                'longitude' => $lng,
                'address' => $address ?: "Location at " . round($lat, 5) . ", " . round($lng, 5),
            ];
        }

        // 3. Plain text street address (forward geocode via Google Maps)
        $geocoded = $this->forwardGeocode($textToParse);
        if ($geocoded) {
            return [
                'latitude' => $geocoded['lat'],
                'longitude' => $geocoded['lng'],
                'address' => $geocoded['address'] ?: $textToParse,
            ];
        }

        // 4. Fallback for text addresses >= 5 characters when geocoding is unavailable
        if (mb_strlen($textToParse) >= 5) {
            $defaultLocation = $this->getDefaultLocation();
            return [
                'latitude' => $defaultLocation['lat'],
                'longitude' => $defaultLocation['lng'],
                'address' => $textToParse,
            ];
        }

        return [
            'latitude' => null,
            'longitude' => null,
            'address' => null,
        ];
    }

    /**
     * Reverse geocode coordinates to physical address using Google Maps API.
     */
    protected function reverseGeocode(float $lat, float $lng): ?string
    {
        $apiKey = BusinessSetting::where('key', 'map_api_key_server')->first()?->value
            ?? BusinessSetting::where('key', 'map_api_key')->first()?->value;

        if (!$apiKey) {
            return null;
        }

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(5)->get('https://maps.googleapis.com/maps/api/geocode/json', [
                'latlng' => "{$lat},{$lng}",
                'key' => $apiKey,
            ]);

            if ($response->successful() && $response->json('status') === 'OK') {
                return $response->json('results.0.formatted_address');
            }
        } catch (\Throwable $e) {
            Log::warning('WhatsApp reverse geocode failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Forward geocode address string to coordinates using Google Maps API.
     */
    protected function forwardGeocode(string $address): ?array
    {
        $apiKey = BusinessSetting::where('key', 'map_api_key_server')->first()?->value
            ?? BusinessSetting::where('key', 'map_api_key')->first()?->value;

        if (!$apiKey) {
            return null;
        }

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(5)->get('https://maps.googleapis.com/maps/api/geocode/json', [
                'address' => $address,
                'key' => $apiKey,
            ]);

            if ($response->successful() && $response->json('status') === 'OK') {
                $location = $response->json('results.0.geometry.location');
                return [
                    'lat' => (float) ($location['lat'] ?? 0),
                    'lng' => (float) ($location['lng'] ?? 0),
                    'address' => $response->json('results.0.formatted_address') ?? $address,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('WhatsApp forward geocode failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Get default coordinates fallback.
     */
    protected function getDefaultLocation(): array
    {
        $defaultSetting = BusinessSetting::where('key', 'default_location')->first()?->value;
        if ($defaultSetting) {
            $decoded = json_decode($defaultSetting, true);
            if (!empty($decoded['lat']) && !empty($decoded['lng'])) {
                return [
                    'lat' => (float) $decoded['lat'],
                    'lng' => (float) $decoded['lng'],
                ];
            }
        }

        return [
            'lat' => 6.5244,
            'lng' => 3.3792,
        ];
    }

    /**
     * Validate step data.
     */
    protected function validateStep(string $step, array $data): array
    {
        $rules = match ($step) {
            'business_basics' => [
                'business_name' => 'required|string|min:2|max:100',
                'business_description' => 'nullable|string|max:500',
            ],
            'module_selection' => [
                'module_id' => 'required|integer|exists:modules,id',
            ],
            'category_selection' => [
                'category_id' => 'required|integer|exists:categories,id',
            ],
            'location' => [
                'latitude' => 'required|numeric|between:-90,90',
                'longitude' => 'required|numeric|between:-180,180',
                'address' => 'required|string|min:3|max:1000',
            ],
            'zone_selection' => [
                'zone_id' => 'required|integer|exists:zones,id',
            ],
            'operating_hours' => [
                'operating_hours' => 'required_without:schedule|string|max:255',
                'schedule' => 'nullable|string|max:255',
            ],
            'delivery_time' => [
                'delivery_time' => 'required|string|max:100',
            ],
            'owner_info' => [
                'f_name' => 'required|string|min:2|max:100',
                'l_name' => 'nullable|string|max:100',
            ],
            'contact_info' => [
                'email' => 'required|email|unique:vendors,email',
                'phone' => 'required|string|min:7|max:20',
            ],
            'account_password' => isset($data['has_password'])
                ? ['has_password' => 'required|accepted']
                : ['password' => ['required', 'string', Password::min(8)->mixedCase()->letters()->numbers()->symbols()]],
            'store_branding' => [
                'logo_media_id' => 'required|integer|exists:whatsapp_media,id',
            ],
            'business_plan' => [
                'business_plan' => 'required|string|in:commission-base,subscription-base',
            ],
            'terms_acceptance' => [
                'terms_accepted' => 'required|accepted',
            ],
            'privacy_acceptance' => [
                'privacy_accepted' => 'required|accepted',
            ],
            'kyc_documents' => [
                'tin' => 'nullable|string|max:100',
                'cac_number' => 'nullable|string|max:100',
                'nin' => 'nullable|string|max:50',
                'tin_media_id' => 'nullable|integer|exists:whatsapp_media,id',
            ],
            'documents' => [
                'media_id' => 'nullable|integer|exists:whatsapp_media,id',
            ],
            default => [],
        };

        $validator = Validator::make($data, $rules);

        return [
            'valid' => !$validator->fails(),
            'errors' => $validator->errors()->toArray(),
        ];
    }

    /**
     * Send human-friendly validation errors to user.
     */
    protected function sendValidationErrors(
        WhatsAppConversation $conversation,
        WhatsAppContact $contact,
        array $errors,
        \Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway $gateway
    ): void {
        $step = $conversation->current_step ?? 'welcome';

        $session = OnboardingSession::find($conversation->onboarding_session_id);

        $message = match ($step) {
            'business_basics' => "Please provide a valid shop name for your business (between 2 and 100 characters).",
            'module_selection' => "Please choose a valid business module from the list (e.g. Grocery, Food, Pharmacy, etc.).",
            'category_selection' => "We couldn't recognize that category. Please choose from available categories or type your category name.",
            'location' => "We couldn't detect your shop location. 📍\n\nPlease do one of the following:\n1. Tap 📎 and share your *Location pin* on WhatsApp\n2. Send a Google Maps link\n3. Type your full physical shop address (e.g. *12 Marina Road, Lagos*)",
            'zone_selection' => "Please select or confirm your business operating zone from the available options.",
            'operating_hours' => "Please select or reply with your store's regular operating hours (e.g. *Mon-Sat 8am - 8pm*).",
            'delivery_time' => "Please select or reply with your store's approximate delivery window (e.g. *20-40 min*).",
            'owner_info' => "Please enter your first and last name (e.g. *Rasheed Bello*).",
            'contact_info' => isset($errors['email']) && in_array('The email has already been taken.', $errors['email'])
                ? "This email address is already registered to another vendor account. Please provide a different email address."
                : (isset($errors['phone']) && in_array('The phone has already been taken.', $errors['phone'])
                    ? "This phone number is already registered to an approved vendor account. Reply *Support* if you need assistance."
                    : "Please enter a valid email address for account notifications (e.g. *yourshop@gmail.com*)."),
            'account_password' => "🔒 *Security Reminder*\n\nFor your account protection, passwords cannot be sent in WhatsApp messages.\n\nPlease tap the secure link below to set your vendor dashboard password over encrypted HTTPS:\n\n👉 " . ($session ? $this->generateSecurePasswordUrl($session) : url('/')) . "\n\n⏳ *This secure link is single-use and expires in 15 minutes.*",
            'store_branding' => "⚠️ *Store Logo is Required*\n\nYour store logo is mandatory (matching web application requirements).\n\nSpecifications:\n• Allowed Formats: JPG, JPEG, PNG, WEBP\n• File Size: Max 2 MB\n• Aspect Ratio: 1:1 Square (e.g. 500x500 px)\n\n*(Skip is not permitted)*\n\nPlease tap 📎 or camera to upload your store logo photo:",
            'business_plan' => "Please choose a valid business plan. Tap *💼 Commission-Based* or *📅 Subscription Plan*.",
            'terms_acceptance' => "You must accept MyTijaara's Vendor Terms and Conditions (https://mytijaara.com/terms) to proceed. Tap *✅ Accept Terms* or reply *Accept*.",
            'privacy_acceptance' => "You must accept MyTijaara's Merchant Privacy Policy (https://mytijaara.com/privacy-policy) to proceed. Tap *✅ Accept Privacy* or reply *Accept*.",
            'kyc_documents' => "Please provide a valid TIN / CAC / NIN number, upload a document (max 2MB), or reply *Skip* to complete verification later in your dashboard.",
            'documents' => "Please upload a photo or document of your ID or business registration, or reply *Skip* to continue.",
            default => "Please check your input and try again, or reply *Support* if you need help.",
        };

        if ($step === 'account_password' && $session) {
            $passwordUrl = $this->generateSecurePasswordUrl($session);
            $gateway->sendCtaUrlMessage(
                $contact->phone_number,
                "🔒 *Security Reminder*\n\nFor your account protection and privacy, passwords cannot be entered in WhatsApp messages.\n\nPlease tap the button below to set your vendor dashboard password securely over HTTPS:\n\n👉 {$passwordUrl}\n\n⏳ *This secure link is single-use and expires in 15 minutes.*",
                'Set Password 🔐',
                $passwordUrl,
                'MyTijaara Security'
            );
            return;
        }

        $gateway->sendTextMessage($contact->phone_number, $message);

        // For interactive steps, re-send prompt with buttons/list
        if ($step === 'module_selection') {
            $this->sendStepPrompt($conversation, $contact, 'module_selection', $gateway);
        } elseif ($step === 'zone_selection') {
            $this->sendStepPrompt($conversation, $contact, 'zone_selection', $gateway);
        } elseif ($step === 'operating_hours') {
            $this->sendStepPrompt($conversation, $contact, 'operating_hours', $gateway);
        } elseif ($step === 'delivery_time') {
            $this->sendStepPrompt($conversation, $contact, 'delivery_time', $gateway);
        } elseif ($step === 'category_selection') {
            $sections = $this->getCategorySections();
            if (!empty($sections[0]['rows'])) {
                $gateway->sendListMessage(
                    $contact->phone_number,
                    "Select your business category:",
                    $sections,
                    'Business Categories'
                );
            }
        } elseif ($step === 'business_plan') {
            $this->sendStepPrompt($conversation, $contact, 'business_plan', $gateway);
        } elseif ($step === 'terms_acceptance') {
            $this->sendStepPrompt($conversation, $contact, 'terms_acceptance', $gateway);
        } elseif ($step === 'privacy_acceptance') {
            $this->sendStepPrompt($conversation, $contact, 'privacy_acceptance', $gateway);
        }
    }

    /**
     * Advance to next step or complete onboarding.
     */
    protected function advanceToNextStep(
        WhatsAppConversation $conversation,
        WhatsAppContact $contact,
        ?OnboardingSession $session,
        \Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway $gateway
    ): void {
        $inReview = !empty($session?->collected_data['_in_review']);

        if ($inReview) {
            // User was editing a section; return directly to review summary
            $conversation->update(['current_step' => 'review_submit']);
            $session?->update([
                'current_step' => 'review_submit',
                'last_activity_at' => now(),
            ]);

            $this->sendStepPrompt($conversation, $contact, 'review_submit', $gateway);
            return;
        }

        $nextStep = $session?->getNextStep();

        if (!$nextStep) {
            // All steps complete - advance to review_submit
            $conversation->update(['current_step' => 'review_submit']);
            $session?->update([
                'current_step' => 'review_submit',
                'last_activity_at' => now(),
            ]);

            $this->sendStepPrompt($conversation, $contact, 'review_submit', $gateway);
            return;
        }

        // Update conversation state and session state
        $conversation->update(['current_step' => $nextStep]);
        $session?->update([
            'current_step' => $nextStep,
            'last_activity_at' => now(),
        ]);

        // Send next step prompt
        $this->sendStepPrompt($conversation, $contact, $nextStep, $gateway);
    }

    /**
     * Send prompt for specific step.
     */
    /**
     * Send prompt for specific step.
     */
    public function sendStepPrompt(
        WhatsAppConversation $conversation,
        WhatsAppContact $contact,
        string $step,
        \Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway $gateway
    ): void {
        $session = OnboardingSession::find($conversation->onboarding_session_id);

        if ($step === 'review_submit' && $session) {
            $session->updateData(['_in_review' => true]);
            $session->refresh();
        }

        if ($step === 'account_password') {
            $passwordUrl = $session ? $this->generateSecurePasswordUrl($session) : url('/');
            $gateway->sendCtaUrlMessage(
                $contact->phone_number,
                "[Section 3 of 5: Account Security] 🔐\n\nFor your account protection and privacy, dashboard passwords cannot be entered in WhatsApp chat.\n\nPlease tap the button below to set your vendor dashboard password securely over HTTPS:\n\n👉 {$passwordUrl}\n\n⏳ *This secure link is single-use and expires in 15 minutes.*",
                'Set Password 🔐',
                $passwordUrl,
                'MyTijaara Security'
            );
            return;
        }

        $prompts = [
            'business_basics' => [
                'type' => 'text',
                'text' => "[Section 1 of 5: Business Identity] 🏪\n\nGreat! Let's start with your store details.\n\nWhat is your *Shop / Business Name*?\n\n*(e.g. Ronix Essentials)*",
            ],
            'module_selection' => (function () {
                $modules = Module::active()->notParcel()->get(['id', 'module_name', 'module_type']);
                if ($modules->count() <= 3) {
                    $buttons = $modules->map(function ($m) {
                        return [
                            'id' => 'mod_' . $m->id,
                            'title' => mb_substr($m->module_name, 0, 20),
                        ];
                    })->toArray();
                    return [
                        'type' => 'button',
                        'body' => "[Section 1 of 5: Business Module] 📦\n\nWhat type of business are you onboarding?\n\nSelect your primary business module below:",
                        'buttons' => $buttons,
                    ];
                }

                $rows = $modules->map(function ($m) {
                    return [
                        'id' => 'mod_' . $m->id,
                        'title' => mb_substr($m->module_name, 0, 24),
                        'description' => ucfirst($m->module_type) . ' business',
                    ];
                })->toArray();

                return [
                    'type' => 'list',
                    'body' => "[Section 1 of 5: Business Module] 📦\n\nSelect the business module that best describes your store:",
                    'sections' => [[
                        'title' => 'Available Modules',
                        'rows' => $rows,
                    ]],
                ];
            })(),
            'category_selection' => [
                'type' => 'list',
                'body' => '[Section 1 of 5: Category] 📂\n\nWhat category best describes your business?',
                'sections' => $this->getCategorySections(),
            ],
            'location' => [
                'type' => 'text',
                'text' => "[Section 2 of 5: Store Location] 📍\n\nWhere is your shop physically located?\n\n1. Tap 📎 and share your *Location pin* on WhatsApp\n2. Send a Google Maps link\n3. Type your full physical street address (e.g. *12 Marina Road, Lagos*)",
            ],
            'zone_selection' => (function () use ($session) {
                $detectedName = $session?->collected_data['zone_name'] ?? null;
                $zones = Zone::active()->get(['id', 'name']);

                if ($detectedName && $zones->count() <= 2) {
                    $detectedZoneId = $session?->collected_data['zone_id'] ?? ($zones->first()?->id ?? 1);
                    return [
                        'type' => 'button',
                        'body' => "[Section 2 of 5: Operating Zone] 🌐\n\nBased on your location, your store is in:\n• Zone: *{$detectedName}* ✅\n\nConfirm your operating zone to continue:",
                        'buttons' => [
                            ['id' => 'zone_' . $detectedZoneId, 'title' => '✅ Confirm Zone'],
                        ],
                    ];
                }

                if ($zones->count() <= 3) {
                    $buttons = $zones->map(function ($z) {
                        return [
                            'id' => 'zone_' . $z->id,
                            'title' => mb_substr($z->name, 0, 20),
                        ];
                    })->toArray();

                    return [
                        'type' => 'button',
                        'body' => "[Section 2 of 5: Operating Zone] 🌐\n\nSelect the business zone where your store operates:",
                        'buttons' => $buttons,
                    ];
                }

                $rows = $zones->map(function ($z) {
                    return [
                        'id' => 'zone_' . $z->id,
                        'title' => mb_substr($z->name, 0, 24),
                        'description' => 'Operating Zone #' . $z->id,
                    ];
                })->toArray();

                return [
                    'type' => 'list',
                    'body' => "[Section 2 of 5: Operating Zone] 🌐\n\nSelect your store operating zone:",
                    'sections' => [[
                        'title' => 'Operating Zones',
                        'rows' => $rows,
                    ]],
                ];
            })(),
            'operating_hours' => [
                'type' => 'button',
                'body' => "[Section 2 of 5: Operating Hours] 🕒\n\nWhen will your store be open for customer orders?\n\nSelect a standard schedule or reply with your custom hours (e.g. *Mon-Fri 9am-6pm*):",
                'buttons' => [
                    ['id' => 'hours_standard', 'title' => '🕒 Mon-Sat (8am-8pm)'],
                    ['id' => 'hours_everyday', 'title' => '🕒 Everyday (8am-10pm)'],
                    ['id' => 'hours_247', 'title' => '🕒 24/7 Always Open'],
                ],
            ],
            'delivery_time' => [
                'type' => 'button',
                'body' => "[Section 2 of 5: Delivery Time] 🚚\n\nWhat is your typical order preparation & delivery window?\n\nSelect an option or reply with your custom delivery time:",
                'buttons' => [
                    ['id' => 'deliv_20_40', 'title' => '⚡ 20 - 40 min'],
                    ['id' => 'deliv_30_60', 'title' => '📦 30 - 60 min'],
                    ['id' => 'deliv_1_2hr', 'title' => '🚚 1 - 2 hours'],
                ],
            ],
            'owner_info' => [
                'type' => 'text',
                'text' => "[Section 3 of 5: Owner Information] 👤\n\nWho is the primary business owner / manager?\n\nPlease enter your *First Name* and *Last Name* (e.g. *Rasheed Bello*):",
            ],
            'contact_info' => [
                'type' => 'text',
                'text' => "[Section 3 of 5: Contact Information] 📧\n\nWhat is your *email address* for account notifications and vendor dashboard login?\n\n*(e.g. yourshop@gmail.com)*",
            ],
            'account_password' => [
                'type' => 'text',
                'text' => "[Section 3 of 5: Account Security] 🔐\n\nCreate a secure password for logging into your vendor dashboard:\n\nRequirements (matching web):\n• At least 8 characters\n• Uppercase & lowercase letters\n• At least one number (0-9)\n• At least one symbol (!@#$%^&*)\n\n*(Example: ShopPass@2026)*",
            ],
            'store_branding' => [
                'type' => 'text',
                'text' => "[Section 4 of 5: Store Branding] 🖼️\n\nPlease upload your *Store Logo*.\n\nSpecifications (matching web requirements):\n• Allowed Formats: JPG, JPEG, PNG, WEBP\n• File Size: Max 2 MB\n• Aspect Ratio: 1:1 Square (e.g. 500x500 px)\n• Requirement: *Mandatory* (No skip)\n\nPlease tap 📎 or camera to send your store logo photo now:",
            ],
            'business_plan' => [
                'type' => 'button',
                'body' => "[Section 5 of 5: Business Plan] 💼\n\nChoose your preferred business model on MyTijaara:\n\n• *Commission-Based*: Pay only a percentage on completed sales. No upfront fee.\n• *Subscription Plan*: Fixed monthly plan with 0% commission on orders.\n\nSelect your plan:",
                'buttons' => [
                    ['id' => 'plan_commission', 'title' => '💼 Commission-Based'],
                    ['id' => 'plan_subscription', 'title' => '📅 Subscription Plan'],
                ],
            ],
            'terms_acceptance' => [
                'type' => 'button',
                'body' => "[Section 5 of 5: Terms & Conditions] 📜\n\nPlease review MyTijaara's Vendor Terms and Conditions:\n🔗 https://mytijaara.com/terms\n\nDo you accept the Vendor Terms and Conditions to proceed?",
                'buttons' => [
                    ['id' => 'accept_terms', 'title' => '✅ Accept Terms'],
                    ['id' => 'decline_terms', 'title' => '❌ Decline'],
                ],
            ],
            'privacy_acceptance' => [
                'type' => 'button',
                'body' => "[Section 5 of 5: Privacy Policy] 🔒\n\nPlease review MyTijaara's Merchant Privacy Policy:\n🔗 https://mytijaara.com/privacy-policy\n\nDo you accept the Merchant Privacy Policy to proceed?",
                'buttons' => [
                    ['id' => 'accept_privacy', 'title' => '✅ Accept Privacy'],
                    ['id' => 'decline_privacy', 'title' => '❌ Decline'],
                ],
            ],
            'kyc_documents' => [
                'type' => 'text',
                'text' => "[Section 5 of 5: KYC Verification] 📑\n\nTo speed up your store approval, provide your business or identity verification:\n\n1. *TIN*: Tax Identification Number + Tax Certificate\n2. *CAC*: CAC RC/BN Registration Number + Certificate\n3. *NIN*: National Identity Number + ID card\n\nReply with your TIN / CAC / NIN number, upload a document/certificate (max 2MB), or reply *Skip* to complete verification later in your merchant dashboard.",
            ],
            'documents' => [
                'type' => 'text',
                'text' => "Please upload any required documents (business license, ID, etc.)\n\nYou can send photos or PDFs, or reply *Skip* to continue.",
            ],
            'review_submit' => [
                'type' => 'button',
                'body' => $step === 'review_submit' ? $this->buildReviewSummary($session) : '',
                'buttons' => [
                    ['id' => 'submit', 'title' => '✅ Submit'],
                    ['id' => 'edit', 'title' => '✏️ Edit'],
                    ['id' => 'cancel', 'title' => '❌ Cancel'],
                ],
            ],
        ];

        $prompt = $prompts[$step] ?? ['text' => 'Please continue...'];

        if (($prompt['type'] ?? 'text') === 'list') {
            $gateway->sendListMessage(
                $contact->phone_number,
                $prompt['body'],
                $prompt['sections'],
                'Step: ' . ucfirst(str_replace('_', ' ', $step))
            );
        } elseif (($prompt['type'] ?? 'text') === 'button') {
            $gateway->sendButtonMessage(
                $contact->phone_number,
                $prompt['body'],
                $prompt['buttons'],
                $step === 'review_submit' ? 'Review Your Application' : 'MyTijaara Onboarding'
            );
        } else {
            $gateway->sendTextMessage($contact->phone_number, $prompt['text']);
        }
    }

    /**
     * Generate a single-use secure HTTPS password setup URL with 15-minute TTL.
     */
    public function generateSecurePasswordUrl(OnboardingSession $session): string
    {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = now()->addMinutes(15);

        $session->updateData([
            '_pwd_token_hash' => $tokenHash,
            '_pwd_token_expires_at' => $expiresAt->toIso8601String(),
        ]);

        \Illuminate\Support\Facades\Cache::put('wa_pwd_token:' . $tokenHash, $session->id, $expiresAt);

        return route('whatsapp.onboarding.password', ['token' => $token]);
    }

    /**
     * Build review summary from session data.
     */
    public function buildReviewSummary(?OnboardingSession $session): string
    {
        $data = $session?->collected_data ?? [];

        $businessName = $data['business_name'] ?? 'Not provided';
        $moduleName = $data['module_name'] ?? (!empty($data['module_id']) ? (Module::find($data['module_id'])?->module_name ?? 'Standard') : ($data['category_name'] ?? 'Not selected'));
        $owner = trim(($data['f_name'] ?? '') . ' ' . ($data['l_name'] ?? ''));
        if ($owner === '') {
            $owner = $data['owner_name'] ?? 'Owner';
        }
        $address = $data['address'] ?? 'Not provided';
        $zoneName = $data['zone_name'] ?? (!empty($data['zone_id']) ? (Zone::find($data['zone_id'])?->name ?? 'Default Zone') : 'Pending');
        $hours = $data['operating_hours'] ?? ($data['schedule'] ?? 'Mon - Sat (8am - 8pm)');
        $delivery = $data['delivery_time'] ?? '20-40 min';
        $email = $data['email'] ?? 'Not provided';
        $phone = $data['phone'] ?? 'Not provided';

        $passwordStatus = (!empty($data['password_hash']) || !empty($data['has_password'])) ? 'Encrypted & Secured 🔒' : 'Pending';

        $logoStatus = !empty($data['logo_media_id']) || !empty($data['has_logo']) || !empty($data['media_id'])
            ? 'Uploaded (1:1 Verified) 🖼️'
            : 'Required ⚠️';

        $plan = ($data['business_plan'] ?? '') === 'subscription-base'
            ? 'Subscription Plan 📅'
            : 'Commission-Based 💼';

        $terms = !empty($data['terms_accepted']) ? 'Accepted ✅' : 'Pending ⏳';
        $privacy = !empty($data['privacy_accepted']) ? 'Accepted ✅' : 'Pending ⏳';

        $kyc = 'Optional (Upload Later in Dashboard)';
        if (!empty($data['tin'])) {
            $kyc = "TIN: {$data['tin']}";
        } elseif (!empty($data['cac_number'])) {
            $kyc = "CAC: {$data['cac_number']}";
        } elseif (!empty($data['nin'])) {
            $kyc = "NIN: {$data['nin']}";
        } elseif (!empty($data['tin_media_id'])) {
            $kyc = 'Document Uploaded 📑';
        }

        return "📋 *Review Your Application Details*\n\n" .
            "🏪 *Business Identity:*\n" .
            "• Shop Name: *{$businessName}*\n" .
            "• Module: *{$moduleName}*\n\n" .
            "📍 *Location & Operations:*\n" .
            "• Address: *{$address}*\n" .
            "• Zone: *{$zoneName}*\n" .
            "• Operating Hours: *{$hours}*\n" .
            "• Delivery Time: *{$delivery}*\n\n" .
            "👤 *Owner & Security:*\n" .
            "• Owner: *{$owner}*\n" .
            "• Email: *{$email}*\n" .
            "• Phone: *{$phone}*\n" .
            "• Password: *{$passwordStatus}*\n\n" .
            "🖼️ *Branding:*\n" .
            "• Logo: *{$logoStatus}*\n\n" .
            "📜 *Plan, Policies & KYC:*\n" .
            "• Business Plan: *{$plan}*\n" .
            "• Terms & Conditions: *{$terms}*\n" .
            "• Privacy Policy: *{$privacy}*\n" .
            "• KYC Verification: *{$kyc}*\n\n" .
            "Ready to submit? Tap *Submit* below to finalize your application!";
    }

    /**
     * Get category sections for list message.
     */
    protected function getCategorySections(): array
    {
        $categories = \App\Models\Category::where('status', 1)
            ->where('parent_id', 0)
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name'])
            ->map(function ($cat) {
                return [
                    'id' => (string) $cat->id,
                    'title' => $cat->name,
                ];
            })->toArray();

        return [[
            'title' => 'Business Categories',
            'rows' => $categories,
        ]];
    }

    /**
     * Submit the vendor application to MyTijaara backend.
     * This reuses the existing vendor registration logic.
     */
    public function submitApplication(
        WhatsAppConversation $conversation,
        WhatsAppContact $contact,
        ?OnboardingSession $session,
        \Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway $gateway
    ): void {
        try {
            $data = $session?->collected_data ?? [];

            $phone = $data['phone'] ?? $contact->phone_number;
            $email = $data['email'] ?? null;

            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $gateway->sendTextMessage($contact->phone_number, "⚠️ A valid email address is required. Please reply *Edit Email* to provide your email address.");
                return;
            }

            // Check if vendor already exists with this phone or email
            $existingVendorByPhone = Vendor::where('phone', $phone)->first();
            $existingVendorByEmail = Vendor::where('email', $email)->first();

            if ($existingVendorByEmail && (!$existingVendorByPhone || $existingVendorByEmail->id !== $existingVendorByPhone->id)) {
                $gateway->sendTextMessage($contact->phone_number, "⚠️ The email *{$email}* is already associated with another vendor account. Please reply *Edit Email* to use a different email address.");
                return;
            }

            DB::beginTransaction();

            $fName = $data['f_name'] ?? ($data['business_name'] ?? 'Business');
            $lName = $data['l_name'] ?? 'Owner';
            $passwordHash = $data['password_hash'] ?? bcrypt(\Illuminate\Support\Str::random(16));

            if ($existingVendorByPhone) {
                $existingStore = Store::where('vendor_id', $existingVendorByPhone->id)->first();
                if ($existingStore && (int)$existingStore->status === 1) {
                    DB::rollBack();
                    $gateway->sendTextMessage(
                        $contact->phone_number,
                        "ℹ️ An approved store (*{$existingStore->name}*) is already registered with this phone number.\n\nYou can log into your merchant dashboard at:\nhttps://dashboard.mytijaara.com/vendor/auth/login\n\nIf you need assistance, reply *Support*."
                    );
                    return;
                }

                // Refresh existing vendor record from previous test / unapproved application
                $vendor = $existingVendorByPhone;
                $vendor->f_name = $fName;
                $vendor->l_name = $lName;
                $vendor->email = $email;
                if (!empty($passwordHash)) {
                    $vendor->password = $passwordHash;
                }
                $vendor->status = null; // CRITICAL: null guarantees store appears in Admin Pending Requests!
                $vendor->save();
            } else {
                $vendor = new Vendor();
                $vendor->f_name = $fName;
                $vendor->l_name = $lName;
                $vendor->email = $email;
                $vendor->phone = $phone;
                $vendor->password = $passwordHash;
                $vendor->status = null; // CRITICAL: null guarantees store appears in Admin Pending Requests!
                $vendor->save();
            }

            // Link contact to vendor
            $contact->linkToApplicant();
            $contact->update(['vendor_id' => $vendor->id]);

            // Determine zone
            $zone = null;
            if (!empty($data['latitude']) && !empty($data['longitude'])) {
                $zone = Zone::whereContains('coordinates',
                    new \MatanYadaev\EloquentSpatial\Objects\Point(
                        (float)$data['latitude'],
                        (float)$data['longitude'],
                        4326
                    ))->first();
            }

            if (!$zone && !empty($data['zone_id'])) {
                $zone = Zone::find($data['zone_id']);
            }
            if (!$zone) {
                $zone = Zone::where('status', 1)->first() ?? Zone::first();
            }

            // Determine module
            $moduleId = $data['module_id'] ?? null;
            if (!$moduleId && !empty($data['category_id'])) {
                $category = \App\Models\Category::find($data['category_id']);
                $moduleId = $category?->module_id;
            }
            if (!$moduleId) {
                $moduleId = config('module.current_module_id') ?? (Module::where('status', 1)->first()?->id ?? 1);
            }
            $module = Module::find($moduleId);

            // Canonical media placement to store/ and store/cover/
            $targetDisk = Helpers::getDisk();
            $logoName = 'def.png';
            $coverName = 'def.png';
            $tinCertName = null;

            // Handle store logo (mandatory)
            $logoMediaId = $data['logo_media_id'] ?? ($data['media_id'] ?? null);
            if (!empty($logoMediaId)) {
                $logoMedia = \Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMedia::find($logoMediaId);
                if ($logoMedia && !empty($logoMedia->file_path)) {
                    $sourceDisk = $logoMedia->storage_disk ?: 'public';
                    if (\Illuminate\Support\Facades\Storage::disk($sourceDisk)->exists($logoMedia->file_path)) {
                        $content = \Illuminate\Support\Facades\Storage::disk($sourceDisk)->get($logoMedia->file_path);
                        $ext = pathinfo($logoMedia->file_path, PATHINFO_EXTENSION) ?: 'png';
                        $logoName = \Carbon\Carbon::now()->toDateString() . '-' . uniqid() . '.' . $ext;
                        \Illuminate\Support\Facades\Storage::disk($targetDisk)->put('store/' . $logoName, $content);
                    }
                }
            }

            // Handle cover photo (optional)
            if (!empty($data['cover_media_id'])) {
                $coverMedia = \Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMedia::find($data['cover_media_id']);
                if ($coverMedia && !empty($coverMedia->file_path)) {
                    $sourceDisk = $coverMedia->storage_disk ?: 'public';
                    if (\Illuminate\Support\Facades\Storage::disk($sourceDisk)->exists($coverMedia->file_path)) {
                        $content = \Illuminate\Support\Facades\Storage::disk($sourceDisk)->get($coverMedia->file_path);
                        $ext = pathinfo($coverMedia->file_path, PATHINFO_EXTENSION) ?: 'png';
                        $coverName = \Carbon\Carbon::now()->toDateString() . '-' . uniqid() . '.' . $ext;
                        \Illuminate\Support\Facades\Storage::disk($targetDisk)->put('store/cover/' . $coverName, $content);
                    }
                }
            }

            // Handle KYC certificate
            if (!empty($data['tin_media_id'])) {
                $tinMedia = \Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMedia::find($data['tin_media_id']);
                if ($tinMedia && !empty($tinMedia->file_path)) {
                    $sourceDisk = $tinMedia->storage_disk ?: 'public';
                    if (\Illuminate\Support\Facades\Storage::disk($sourceDisk)->exists($tinMedia->file_path)) {
                        $content = \Illuminate\Support\Facades\Storage::disk($sourceDisk)->get($tinMedia->file_path);
                        $ext = pathinfo($tinMedia->file_path, PATHINFO_EXTENSION) ?: 'png';
                        $tinCertName = \Carbon\Carbon::now()->toDateString() . '-' . uniqid() . '.' . $ext;
                        \Illuminate\Support\Facades\Storage::disk($targetDisk)->put('store/' . $tinCertName, $content);
                    }
                }
            }

            $businessModel = ($data['business_plan'] ?? '') === 'subscription-base' ? 'subscription' : 'commission';

            // Create or update Store (pending status: 0)
            $store = Store::where('vendor_id', $vendor->id)->first() ?? new Store();
            $store->name = $data['business_name'] ?? 'Store';
            $store->phone = $phone;
            $store->email = $email;
            $store->logo = $logoName;
            $store->cover_photo = $coverName;
            $store->latitude = $data['latitude'] ?? '6.5244';
            $store->longitude = $data['longitude'] ?? '3.3792';
            $store->address = $data['address'] ?? 'Nigeria';
            $store->vendor_id = $vendor->id;
            $store->zone_id = $zone?->id ?? 1;
            $store->module_id = $module?->id ?? 1;
            $store->status = 0; // 0 = inactive, awaiting admin approval
            $store->store_business_model = $businessModel;
            $store->delivery_time = $data['delivery_time'] ?? '20-40 min';
            $store->tin = $data['tin'] ?? ($data['cac_number'] ?? null);
            $store->tin_expire_date = $data['tin_expire_date'] ?? null;
            if ($tinCertName) {
                $store->tin_certificate_image = $tinCertName;
            }

            // Structured metadata for Nigerian KYC & onboarding audit
            $metadata = [
                'onboarding_source' => 'whatsapp',
                'kyc_type' => $data['kyc_type'] ?? (!empty($data['tin']) ? 'tin' : (!empty($data['cac_number']) ? 'cac' : (!empty($data['nin']) ? 'nin' : 'none'))),
                'cac_number' => $data['cac_number'] ?? null,
                'nin' => $data['nin'] ?? null,
                'operating_hours_raw' => $data['operating_hours'] ?? null,
                'terms_accepted_at' => now()->toIso8601String(),
                'privacy_accepted_at' => now()->toIso8601String(),
            ];
            $store->meta_data = json_encode($metadata);
            $store->save();

            // Translations matching VendorController.php:168-169
            try {
                Helpers::add_or_update_translations(
                    request: new \Illuminate\Http\Request([
                        'lang' => ['default'],
                        'name' => ['default' => $store->name],
                        'address' => ['default' => $store->address],
                    ]),
                    key_data: 'name',
                    name_field: 'name',
                    model_name: 'Store',
                    data_id: $store->id,
                    data_value: $store->name
                );
                Helpers::add_or_update_translations(
                    request: new \Illuminate\Http\Request([
                        'lang' => ['default'],
                        'name' => ['default' => $store->name],
                        'address' => ['default' => $store->address],
                    ]),
                    key_data: 'address',
                    name_field: 'address',
                    model_name: 'Store',
                    data_id: $store->id,
                    data_value: $store->address
                );
            } catch (\Throwable $transEx) {
                Log::warning('Translations insertion warning: ' . $transEx->getMessage());
            }

            // Handle subscription if subscription-base selected
            if ($businessModel === 'subscription') {
                $package = null;
                if (!empty($data['package_id'])) {
                    $package = SubscriptionPackage::find($data['package_id']);
                }
                if (!$package) {
                    $package = SubscriptionPackage::where('status', 1)->first();
                }

                if ($package) {
                    $store->update([
                        'package_id' => $package->id,
                    ]);

                    StoreSubscription::updateOrCreate(
                        ['store_id' => $store->id],
                        [
                            'package_id' => $package->id,
                            'expiry_date' => now()->addDays($package->validity)->format('Y-m-d'),
                            'validity' => $package->validity,
                            'max_order' => $package->max_order,
                            'max_product' => $package->max_product,
                            'pos' => $package->pos ?? 0,
                            'mobile_app' => $package->mobile_app ?? 0,
                            'chat' => $package->chat ?? 0,
                            'review' => $package->review ?? 0,
                            'self_delivery' => $package->self_delivery ?? 0,
                            'status' => 0, // Inactive pending approval
                            'is_trial' => $data['is_trial'] ?? 0,
                        ]
                    );
                }
            }

            // Insert operating hours schedule
            try {
                $this->insertStoreSchedule($store, $data['operating_hours'] ?? null);
            } catch (\Throwable $schedEx) {
                Log::warning('Schedule insertion warning: ' . $schedEx->getMessage());
            }

            // Update session
            if ($session) {
                $session->update([
                    'vendor_id' => $vendor->id,
                    'store_id' => $store->id,
                    'status' => 'submitted',
                    'completed_at' => now(),
                ]);
            }

            // Update conversation
            $conversation->update([
                'vendor_id' => $vendor->id,
                'state' => 'onboarding_completed',
            ]);

            DB::commit();

            // Send confirmation to vendor
            $gateway->sendTextMessage(
                $contact->phone_number,
                "🎉 *Your Application Has Been Submitted!*\n\n" .
                "• Application ID: #{$vendor->id}\n" .
                "• Business: *{$data['business_name']}*\n" .
                "• Owner: *{$fName} {$lName}*\n" .
                "• Status: *Under Review (Pending)* ⏳\n\n" .
                "Our team will review your application within 24-48 hours. " .
                "You will receive a WhatsApp notification here once your store is approved!\n\n" .
                "Thank you for choosing MyTijaara! 🙏"
            );

            // Notify admin (reuse existing email)
            $this->notifyAdmin($vendor, $store);

            OnboardingEvent::log(
                $session?->id ?? 0,
                $contact->id,
                'application_submitted',
                'review_submit',
                ['vendor_id' => $vendor->id, 'store_id' => $store->id]
            );

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Application submission failed', [
                'contact_id' => $contact->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $gateway->sendTextMessage(
                $contact->phone_number,
                "❌ Sorry, there was an error submitting your application. Please try again or contact support."
            );
        }
    }

    /**
     * Insert operating hours schedule into store_schedules table.
     */
    protected function insertStoreSchedule(Store $store, ?string $hoursInput): void
    {
        $raw = strtolower(trim((string)$hoursInput));

        if (str_contains($raw, 'standard') || str_contains($raw, 'mon-sat')) {
            // Monday to Saturday: 08:00 to 20:00 (days 1-6)
            $this->storeLogic->insert_schedule($store->id, [1, 2, 3, 4, 5, 6], '08:00:00', '20:00:00');
        } elseif (str_contains($raw, 'everyday') || str_contains($raw, 'mon-sun')) {
            // Everyday: 08:00 to 22:00 (days 0-6)
            $this->storeLogic->insert_schedule($store->id, [0, 1, 2, 3, 4, 5, 6], '08:00:00', '22:00:00');
        } elseif (str_contains($raw, '24/7') || str_contains($raw, 'always open')) {
            // 24/7: days 0-6 00:00 to 23:59:59
            $this->storeLogic->insert_schedule($store->id, [0, 1, 2, 3, 4, 5, 6], '00:00:00', '23:59:59');
        } else {
            // Default schedule
            $this->storeLogic->insert_schedule($store->id);
        }
    }

    /**
     * Notify admin of new application (reuse existing logic).
     */
    protected function notifyAdmin(Vendor $vendor, Store $store): void
    {
        try {
            $admin = \App\Models\Admin::where('role_id', 1)->first();
            $module = $store->module;

            if ($module && $module->module_type != 'rental' && config('mail.status') && Helpers::get_mail_status('store_registration_mail_status_admin') == '1') {
                Mail::to($admin?->getRawOriginal('email'))->send(new StoreRegistration('pending', $vendor->f_name . ' ' . $vendor->l_name));
            }
        } catch (\Exception $e) {
            Log::error('Admin notification failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Approve vendor application (called from admin panel or service).
     */
    public function approveApplication(int $storeId): bool
    {
        try {
            $store = Store::findOrFail($storeId);
            $vendor = $store->vendor;

            DB::beginTransaction();

            $vendor->update(['status' => 1]);
            $store->update(['status' => 1]);

            // Activate subscription if applicable
            if ($store->store_sub_update_application) {
                $addDays = $store->store_sub_update_application->is_trial
                    ? (int) (BusinessSetting::where('key', 'subscription_free_trial_days')->first()?->value ?? 1)
                    : $store->store_sub_update_application->validity;

                $store->store_sub_update_application->update([
                    'expiry_date' => now()->addDays($addDays)->format('Y-m-d'),
                    'status' => 1,
                ]);
                $store->store_business_model = 'subscription';
            }

            $store->save();
            DB::commit();

            // Send approval email
            if (config('mail.status') && Helpers::get_mail_status('approve_mail_status_store') == '1') {
                Mail::to($vendor->getRawOriginal('email'))->send(new VendorSelfRegistration('approved', $vendor->f_name . ' ' . $vendor->l_name));
            }

            // Dispatch real-time WhatsApp status notification
            \Modules\WhatsAppVendorConcierge\app\Jobs\SendVendorStatusNotification::dispatch(
                $storeId,
                \Modules\WhatsAppVendorConcierge\app\Jobs\SendVendorStatusNotification::TYPE_APPROVED
            )->onQueue(config('whatsapp-vendor-concierge.queue.jobs.send_whatsapp_message', 'notifications'));

            return true;
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Approval failed', ['store_id' => $storeId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Reject vendor application.
     */
    public function rejectApplication(int $storeId, string $reason): bool
    {
        try {
            $store = Store::findOrFail($storeId);
            $vendor = $store->vendor;

            DB::beginTransaction();

            $vendor->update([
                'status' => 0,
                'rejection_note' => $reason,
            ]);

            DB::commit();

            // Send rejection email
            if (config('mail.status') && Helpers::get_mail_status('deny_mail_status_store') == '1') {
                Mail::to($vendor->getRawOriginal('email'))->send(new VendorSelfRegistration('denied', $vendor->f_name . ' ' . $vendor->l_name));
            }

            // Dispatch real-time WhatsApp status notification
            \Modules\WhatsAppVendorConcierge\app\Jobs\SendVendorStatusNotification::dispatch(
                $storeId,
                \Modules\WhatsAppVendorConcierge\app\Jobs\SendVendorStatusNotification::TYPE_DENIED,
                $reason
            )->onQueue(config('whatsapp-vendor-concierge.queue.jobs.send_whatsapp_message', 'notifications'));

            return true;
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Rejection failed', ['store_id' => $storeId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Resume existing onboarding session.
     */
    public function resumeOnboarding(WhatsAppContact $contact, WhatsAppConversation $conversation): ?OnboardingSession
    {
        $session = OnboardingSession::where('contact_id', $contact->id)
            ->where('status', '!=', 'submitted')
            ->where('status', '!=', 'approved')
            ->where('status', '!=', 'rejected')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if ($session) {
            $conversation->update([
                'onboarding_session_id' => $session->id,
                'state' => 'onboarding_active',
                'current_step' => $session->current_step ?? 'welcome',
                'collected_data' => $session->collected_data,
            ]);
        }

        return $session;
    }
}