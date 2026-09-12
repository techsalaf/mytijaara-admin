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
        $data = $this->extractStepData($message, $step, $contact);

        // Validate step data
        $validation = $this->validateStep($step, $data);

        if (!$validation['valid']) {
            $this->sendValidationErrors($conversation, $contact, $validation['errors'], $gateway);
            OnboardingEvent::log(
                $conversation->onboarding_session_id,
                $contact->id,
                'step_failed',
                $step,
                ['data' => $data, 'errors' => $validation['errors']]
            );
            return;
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
            'category_selection' => (function () use ($content, $rawText) {
                $catId = $content['interactive']['list_reply']['id']
                    ?? $content['interactive']['button_reply']['id']
                    ?? null;
                $catName = $content['interactive']['list_reply']['title']
                    ?? $content['interactive']['button_reply']['title']
                    ?? null;

                // If user typed category name or number as text
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

                return [
                    'category_id' => $catId,
                    'category_name' => $catName,
                ];
            })(),
            'location' => $this->resolveLocation($content, $rawText),
            'contact_info' => [
                'email' => strtolower(trim($rawText ?: ($content['text'] ?? ''))),
                'phone' => $contact?->phone_number ?? ($content['text'] ?? null),
            ],
            'operating_hours' => [
                'schedule' => $rawText ?: ($content['text'] ?? null),
            ],
            'documents' => (function () use ($message, $content) {
                // 1. If message already has a media record ID
                if (!empty($message->media_id)) {
                    $media = \Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMedia::find($message->media_id);
                    if ($media) {
                        return ['media_id' => $media->id];
                    }
                    $media = \Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMedia::where('whatsapp_media_id', $message->media_id)->first();
                    if ($media) {
                        return ['media_id' => $media->id];
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
                    return ['media_id' => $media->id];
                }

                // 3. User typed text ("Skip", "none", or continuing without document)
                return ['media_id' => null];
            })(),
            default => [],
        };
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
            'category_selection' => [
                'category_id' => 'required|integer|exists:categories,id',
            ],
            'location' => [
                'latitude' => 'required|numeric|between:-90,90',
                'longitude' => 'required|numeric|between:-180,180',
                'address' => 'required|string|min:3|max:1000',
            ],
            'contact_info' => [
                'email' => 'required|email|unique:vendors,email',
                'phone' => 'required|string|min:7|max:20',
            ],
            'operating_hours' => [
                'schedule' => 'nullable|string|max:500',
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

        $message = match ($step) {
            'business_basics' => "Please provide a valid shop name for your business (between 2 and 100 characters).",
            'category_selection' => "We couldn't recognize that category. Please tap the **Select** button above to choose from available categories, or type your category name (e.g. *Demo category*).",
            'location' => "We couldn't detect your shop location. 📍\n\nPlease do one of the following:\n1. Tap 📎 and share your **Location pin** on WhatsApp\n2. Send a Google Maps link\n3. Type your full physical shop address (e.g. *12 Marina Road, Lagos*)",
            'contact_info' => isset($errors['email']) && in_array('The email has already been taken.', $errors['email'])
                ? "This email address is already registered to another vendor account. Please provide a different email address."
                : (isset($errors['phone']) && in_array('The phone has already been taken.', $errors['phone'])
                    ? "This phone number is already registered to a vendor. Reply *Support* if you need help accessing your account."
                    : "Please enter a valid email address for account verification (e.g., *yourshop@gmail.com*)."),
            'operating_hours' => "Please specify your operating hours (e.g. *Mon-Sat 9am - 8pm, Sun Closed*).",
            'documents' => "Please upload a photo or document of your ID or business registration, or reply *Skip* to continue.",
            default => "Please check your input and try again, or reply *Support* if you need help.",
        };

        $gateway->sendTextMessage($contact->phone_number, $message);

        // For category selection, re-send list prompt so user has the button handy
        if ($step === 'category_selection') {
            $sections = $this->getCategorySections();
            if (!empty($sections[0]['rows'])) {
                $gateway->sendListMessage(
                    $contact->phone_number,
                    "Select your business category:",
                    $sections,
                    'Business Categories'
                );
            }
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
        $nextStep = $session?->getNextStep();

        if (!$nextStep) {
            // All steps complete - submit application
            $this->submitApplication($conversation, $contact, $session, $gateway);
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
    public function sendStepPrompt(
        WhatsAppConversation $conversation,
        WhatsAppContact $contact,
        string $step,
        \Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway $gateway
    ): void {
        $session = OnboardingSession::find($conversation->onboarding_session_id);

        $prompts = [
            'business_basics' => [
                'text' => "Great! Let's start with the basics.\n\nWhat's your business name?",
            ],
            'category_selection' => [
                'type' => 'list',
                'body' => 'What category best describes your business?',
                'sections' => $this->getCategorySections(),
            ],
            'location' => [
                'text' => "Where is your business located?\n\nYou can share your location using WhatsApp's location pin 📍, send a Google Maps link, or type your physical address.",
            ],
            'contact_info' => [
                'text' => "What's your email address for account verification?",
            ],
            'operating_hours' => [
                'text' => "What are your operating hours?\n\nExample: Mon-Fri 9am-10pm, Sat 10am-8pm, Sun closed",
            ],
            'documents' => [
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
                'Review Your Application'
            );
        } else {
            $gateway->sendTextMessage($contact->phone_number, $prompt['text']);
        }
    }

    /**
     * Build review summary from session data.
     */
    protected function buildReviewSummary(?OnboardingSession $session): string
    {
        $data = $session?->collected_data ?? [];
        $name = $data['business_name'] ?? 'Not provided';
        $address = $data['address'] ?? 'Not provided';
        $email = $data['email'] ?? 'Not provided';
        $phone = $data['phone'] ?? 'Not provided';

        return "Please review your application:\n\n" .
            "🏪 Business: {$name}\n" .
            "📍 Location: {$address}\n" .
            "📧 Email: {$email}\n" .
            "📱 Phone: {$phone}\n\n" .
            "Is everything correct?";
    }

    /**
     * Get category sections for list message.
     */
    protected function getCategorySections(): array
    {
        // Fetch active categories from database
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
            $media = $session?->collected_data['media'] ?? [];

            DB::beginTransaction();

            // Create Vendor (pending status)
            $vendor = Vendor::create([
                'f_name' => $data['business_name'] ?? 'Business',
                'l_name' => 'Owner',
                'email' => $data['email'],
                'phone' => $data['phone'],
                'password' => bcrypt(\Str::random(16)), // Temporary, vendor sets on first login
                'status' => 0, // Pending approval
            ]);

            // Link contact to vendor
            $contact->linkToApplicant();
            $contact->update(['vendor_id' => $vendor->id]);

            // Determine module and zone
            $zone = null;
            if (!empty($data['latitude']) && !empty($data['longitude'])) {
                $zone = Zone::whereContains('coordinates',
                    new \MatanYadaev\EloquentSpatial\Objects\Point(
                        $data['latitude'],
                        $data['longitude'],
                        4326
                    ))->first();
            }

            if (!$zone && !empty($data['zone_id'])) {
                $zone = Zone::find($data['zone_id']);
            }
            if (!$zone) {
                $zone = Zone::where('status', 1)->first() ?? Zone::first();
            }

            $category = !empty($data['category_id']) ? \App\Models\Category::find($data['category_id']) : null;
            $moduleId = $data['module_id'] ?? ($category?->module_id ?? (config('module.current_module_id') ?? (Module::where('status', 1)->first()?->id ?? 1)));
            $module = Module::find($moduleId);

            // Create Store (pending status)
            $store = Store::create([
                'name' => $data['business_name'],
                'phone' => $data['phone'],
                'email' => $data['email'],
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'address' => $data['address'],
                'vendor_id' => $vendor->id,
                'zone_id' => $zone?->id ?? 1,
                'module_id' => $module?->id ?? 1,
                'status' => 0, // Pending approval
                'store_business_model' => 'commission', // Default
                'delivery_time' => $data['delivery_time'] ?? '30-40 min',
            ]);

            // Handle subscription if selected
            if (!empty($data['package_id'])) {
                $package = SubscriptionPackage::find($data['package_id']);
                if ($package) {
                    $store->update([
                        'store_business_model' => 'subscription',
                        'package_id' => $package->id,
                    ]);

                    StoreSubscription::create([
                        'store_id' => $store->id,
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
                        'status' => 0, // Will activate on approval
                        'is_trial' => $data['is_trial'] ?? 0,
                    ]);
                }
            }

            // Create store schedule if always_open module
            if ($module && config("module.{$module->module_type}.always_open")) {
                $this->storeLogic->insert_schedule($store->id);
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
                "🎉 Your application has been submitted!\n\n" .
                "Application ID: {$vendor->id}\n" .
                "Business: {$data['business_name']}\n\n" .
                "Our team will review your application within 24-48 hours. " .
                "You'll receive a notification once it's approved.\n\n" .
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
            ]);

            $gateway->sendTextMessage(
                $contact->phone_number,
                "❌ Sorry, there was an error submitting your application. Please try again or contact support."
            );
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
     * Approve vendor application (called from admin panel).
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