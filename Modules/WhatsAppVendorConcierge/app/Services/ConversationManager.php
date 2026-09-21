<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingEvent;
use Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway;
use Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService;
use Modules\WhatsAppVendorConcierge\app\Jobs\SendWhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Jobs\RunVendorAiConversation;
use App\Models\Store;
use App\Models\Module;
use App\Models\Zone;
use Illuminate\Support\Facades\Log;

class ConversationManager
{
    public function __construct(
        protected VendorOnboardingService $onboardingService
    ) {}

    /**
     * Handle welcome message for new contacts.
     */
    public function handleWelcome(WhatsAppConversation $conversation, WhatsAppContact $contact, WhatsAppGateway $gateway): void
    {
        if ($contact->vendor_id && $contact->vendor?->status !== null && (int) $contact->vendor->status === 0) {
            $this->handleRejectedApplicant($conversation, $contact, $gateway);
            return;
        }
        // Check if existing application to resume
        $existingSession = $this->onboardingService->resumeOnboarding($contact, $conversation);

        if ($existingSession) {
            $conversation->update(['state' => 'welcome']);
            $gateway->sendButtonMessage(
                $contact->phone_number,
                "Welcome back! 👋\n\nYou have an incomplete vendor application. Would you like to continue where you left off?",
                [
                    ['id' => 'resume_onboarding', 'title' => '📝 Continue'],
                    ['id' => 'start_fresh', 'title' => '🆕 Start New'],
                    ['id' => 'talk_support', 'title' => '👨‍💬 Talk to Support'],
                ],
                'Welcome Back!'
            );
            return;
        }

        // Check if already a vendor
        if ($contact->isVendor() && $contact->vendor_id) {
            $this->handleExistingVendor($conversation, $contact, $gateway);
            return;
        }

        // New contact - show main menu and transition state out of 'new'
        $conversation->update(['state' => 'welcome']);
        $this->showMainMenu($conversation, $contact, $gateway);
    }

    /**
     * Handle existing vendor - show vendor dashboard menu.
     */
    public function handleExistingVendor(WhatsAppConversation $conversation, WhatsAppContact $contact, WhatsAppGateway $gateway): void
    {
        $vendor = $contact->vendor;
        $store = $vendor->store ?? null;

        $conversation->update([
            'vendor_id' => $vendor->id,
            'state' => 'ai_active',
        ]);

        $storeName = $store?->name ?? 'Your Shop';
        $status = $store?->status ? '🟢 Open' : '🔴 Closed';

        $gateway->sendListMessage(
            $contact->phone_number,
            "Assalaamu Alaikum! Welcome back to MyTijaara. 👋\n\n" .
            "🏪 *{$storeName}*\n" .
            "Status: {$status}\n\n" .
            "What would you like to do today? (Type your response or select an option below)",
            [
                [
                    'title' => 'Store Operations',
                    'rows' => [
                        ['id' => 'add_products', 'title' => '➕ Add Products', 'description' => 'Add new items to catalog'],
                        ['id' => 'manage_shop', 'title' => '🏪 Manage Shop', 'description' => 'View profile and settings'],
                        ['id' => 'view_orders', 'title' => '📦 View Orders', 'description' => 'Check recent orders'],
                        ['id' => 'view_sales', 'title' => '💰 Sales Report', 'description' => 'Summary of shop sales'],
                        ['id' => 'shop_status', 'title' => $store?->active ? '🔴 Pause Shop' : '🟢 Open Shop', 'description' => 'Toggle store availability'],
                        ['id' => 'talk_support', 'title' => '💬 Talk to Support', 'description' => 'Get human assistance'],
                    ],
                ],
            ],
            'MyTijaara Vendor Dashboard',
            null,
            'Dashboard Menu'
        );
    }

    /**
     * Show main menu for new contacts.
     */
    public function showMainMenu(WhatsAppConversation $conversation, WhatsAppContact $contact, WhatsAppGateway $gateway): void
    {
        $gateway->sendButtonMessage(
            $contact->phone_number,
            "Assalaamu Alaikum 👋\n\nWelcome to *MyTijaara* — Nigeria's trusted marketplace for local businesses.\n\nWhat would you like to do?\n\n_(Reply 'Help' or 'FAQ' anytime for info)_",
            [
                ['id' => 'open_shop', 'title' => '🛍️ Open My Shop'],
                ['id' => 'manage_shop', 'title' => '🏪 Manage My Shop'],
                ['id' => 'talk_support', 'title' => '👨‍💬 Talk to Support'],
            ],
            'MyTijaara'
        );
    }

    /**
     * Start the onboarding flow.
     */
    public function startOnboarding(WhatsAppConversation $conversation, WhatsAppContact $contact, WhatsAppGateway $gateway): void
    {
        // Create onboarding session
        $session = OnboardingSession::create([
            'contact_id' => $contact->id,
            'flow_version' => config('whatsapp-vendor-concierge.onboarding.flow_version', '1.0'),
            'status' => 'started',
            'current_step' => 'business_basics',
            'started_at' => now(),
            'last_activity_at' => now(),
            'expires_at' => now()->addMinutes(config('whatsapp-vendor-concierge.onboarding.session_ttl_minutes', 10080)),
            'source' => 'whatsapp',
        ]);

        // Link session to conversation
        $conversation->update([
            'onboarding_session_id' => $session->id,
            'state' => 'onboarding_active',
            'current_step' => 'business_basics',
        ]);

        // Log event
        OnboardingEvent::log($session->id, $contact->id, 'onboarding_started', 'welcome');

        // Send first step prompt
        $this->onboardingService->sendStepPrompt($conversation, $contact, 'business_basics', $gateway);
    }

    /**
     * Handle AI message for registered vendors.
     */
    public function handleAiMessage(WhatsAppConversation $conversation, WhatsAppContact $contact, WhatsAppMessage $message, WhatsAppGateway $gateway): void
    {
        // If vendor sends a photo, guide them into the photo-to-product workflow
        if ($message->type === 'image') {
            $context = $conversation->context ?? [];
            $context['last_product_media_id'] = $message->media_id;
            unset($context['photo_to_product_draft']);
            $conversation->update(['context' => $context]);

            $gateway->sendTextMessage(
                $contact->phone_number,
                "📸 **Product Photo Received!**\n\n" .
                "Your image is being checked. Send the product details, for example:\n\n" .
                "Name: Chicken Shawarma\nDescription: Grilled chicken wrap\nPrice: 3500\nCategory: Meals\nStock: 10\n\n" .
                "Use a category from your business module. If your shop also uses store categories, include Store category: followed by its name. You will review a confirmation before anything is added. Reply Cancel to discard this photo."
            );
            return;
        }

        $context = $conversation->context ?? [];
        if ($message->type === 'text' && !empty($context['last_product_media_id'])) {
            if (strtolower(trim((string) $message->raw_text)) === 'cancel') {
                unset($context['last_product_media_id'], $context['photo_to_product_draft']);
                $conversation->update(['context' => $context]);
                $gateway->sendTextMessage($contact->phone_number, 'Product photo draft discarded.');
                return;
            }
            $store = Store::where('vendor_id', $contact->vendor_id)->firstOrFail();
            $photos = app(PhotoToProductService::class);
            try {
                if (empty($context['photo_to_product_draft'])) {
                    $media = app(CoreAdapters\ProductMedia::class)->owned((int) $context['last_product_media_id'], (int) $contact->vendor_id);
                    $result = $photos->startDraftFromMedia($contact, $conversation, $media);
                    if (!$result['success']) {
                        $gateway->sendTextMessage($contact->phone_number, $result['error']);
                        return;
                    }
                    $context = $conversation->fresh()->context;
                }
                $context['photo_to_product_draft'] = array_replace($context['photo_to_product_draft'],
                    $photos->parseVendorDetails((string) $message->raw_text, $store));
                $conversation->update(['context' => $context]);
                $photos->prepareConfirmation($contact, $conversation, $store);
                unset($context['last_product_media_id'], $context['photo_to_product_draft']);
                $conversation->update(['context' => $context]);
            } catch (\Illuminate\Validation\ValidationException $error) {
                $gateway->sendTextMessage($contact->phone_number, implode("\n", $error->validator->errors()->all()));
            }
            return;
        }

        // Queue AI processing
        RunVendorAiConversation::dispatch($conversation, $contact, $message)
            ->onQueue(config('whatsapp-vendor-concierge.queue.jobs.run_ai_conversation'));
    }

    /**
     * Handle human handoff request.
     */
    public function handleHumanHandoff(WhatsAppConversation $conversation, WhatsAppContact $contact, WhatsAppMessage $message, WhatsAppGateway $gateway): void
    {
        $gateway->sendTextMessage(
            $contact->phone_number,
            "I've connected you with our support team. 🙏\n\n" .
            "A team member will reach out shortly. In the meantime, you can:\n" .
            "• Describe your issue in detail\n" .
            "• Share screenshots if helpful\n" .
            "• Provide your vendor ID: {$contact->vendor_id}\n\n" .
            "Reference: **WHATSAPP-{$conversation->id}**"
        );

        // Log handoff event if onboarding session exists
        if (!empty($conversation->onboarding_session_id)) {
            OnboardingEvent::log(
                $conversation->onboarding_session_id,
                $contact->id,
                'human_handoff',
                $conversation->current_step ?? 'unknown',
                ['conversation_id' => $conversation->id]
            );
        }

        // Notify admin/support team
        $this->notifySupportTeam($conversation, $contact);
    }

    /**
     * Show help menu.
     */
    public function showHelp(WhatsAppConversation $conversation, WhatsAppContact $contact, WhatsAppGateway $gateway): void
    {
        $gateway->sendButtonMessage(
            $contact->phone_number,
            "I'm here to help! 🤝\n\n" .
            "You can ask me things like:\n" .
            "• \"I want to sell on MyTijaara\"\n" .
            "• \"How do I open my shop?\"\n" .
            "• \"Where is my application?\"\n" .
            "• \"How many orders do I have today?\"\n\n" .
            "Or choose an option below (or type 'FAQ'):",
            [
                ['id' => 'start_onboarding', 'title' => '🏪 Become a Vendor'],
                ['id' => 'check_status', 'title' => '📋 Check Status'],
                ['id' => 'talk_support', 'title' => '👨‍💬 Talk to Support'],
            ],
            'How Can I Help?'
        );
    }

    /**
     * Handle button responses from interactive messages.
     */
    public function handleButtonResponse(WhatsAppConversation $conversation, WhatsAppContact $contact, string $buttonId, WhatsAppGateway $gateway): void
    {
        if ($conversation->state === 'human_handoff') {
            return;
        }
        if (preg_match('/^action_(confirm|cancel)_([a-f0-9]{48})$/', $buttonId, $matches)) {
            try {
                $result = app(PendingActionService::class)->confirm($matches[2], $contact->id, $conversation->id, $matches[1] === 'cancel');
            } catch (\Throwable $e) {
                $result = 'This action is unavailable. Please request it again or contact support.';
            }
            $gateway->sendTextMessage($contact->phone_number, $result);
            return;
        }
        $editMap = [
            'edit_business_basics' => 'business_basics',
            'edit_module' => 'module_selection',
            'edit_owner_info' => 'owner_info',
            'edit_category' => 'module_selection',
            'edit_location' => 'location',
            'edit_zone' => 'zone_selection',
            'edit_hours' => 'operating_hours',
            'edit_delivery' => 'delivery_time',
            'edit_contact' => 'contact_info',
            'edit_password' => 'account_password',
            'edit_branding' => 'store_branding',
            'edit_cover' => 'cover_branding',
            'edit_plan' => 'business_plan',
            'edit_terms' => 'terms_acceptance',
            'edit_privacy' => 'privacy_acceptance',
            'edit_kyc' => 'kyc_documents',
            'edit_documents' => 'kyc_documents',
        ];

        if (isset($editMap[$buttonId])) {
            $this->handleEditSection($conversation, $contact, $editMap[$buttonId], $gateway);
            return;
        }

        if (str_starts_with($buttonId, 'edit_cat_')) {
            $this->handleEditCategorySelection($conversation, $contact, $buttonId, $gateway);
            return;
        }

        // Onboarding interactive buttons (business plan, terms, privacy, modules, zones, hours, delivery, kyc)
        $onboardingPrefixes = ['plan_', 'accept_', 'decline_', 'hours_', 'deliv_', 'mod_', 'zone_', 'kyc_'];
        $isOnboardingButton = in_array($buttonId, ['plan_commission', 'plan_subscription', 'accept_terms', 'decline_terms', 'accept_privacy', 'decline_privacy'])
            || array_reduce($onboardingPrefixes, fn($carry, $p) => $carry || str_starts_with($buttonId, $p), false);

        if ($isOnboardingButton) {
            $msg = new WhatsAppMessage();
            $msg->type = 'interactive';
            $msg->content = [
                'interactive' => [
                    'button_reply' => ['id' => $buttonId, 'title' => $buttonId],
                ],
            ];
            $this->onboardingService->processStep($conversation, $contact, $msg, $gateway);
            return;
        }

        match ($buttonId) {
            'resume_onboarding' => $this->resumeOnboarding($conversation, $contact, $gateway),
            'start_fresh' => $this->startFreshOnboarding($conversation, $contact, $gateway),
            'open_shop', 'start_onboarding' => $this->startOnboarding($conversation, $contact, $gateway),
            'check_status' => $this->checkApplicationStatus($conversation, $contact, $gateway),
            'manage_shop' => $contact->isVendor()
                ? $this->handleExistingVendor($conversation, $contact, $gateway)
                : $gateway->sendTextMessage($contact->phone_number, "Please register as a vendor first to manage a shop. Tap *Open My Shop* or reply *Register* to begin!"),
            'learn_selling', 'faq' => $this->showSellingInfo($conversation, $contact, $gateway),
            'talk_support' => $this->initiateHumanHandoff($conversation, $contact, $gateway),
            'add_products' => $this->handleAddProducts($conversation, $contact, $gateway),
            'view_orders' => $this->handleViewOrders($conversation, $contact, $gateway),
            'view_sales' => $this->handleViewSales($conversation, $contact, $gateway),
            'shop_status' => $this->handleShopStatusToggle($conversation, $contact, $gateway),
            'confirm_open_shop' => $this->handleConfirmShopStatus($conversation, $contact, true, $gateway),
            'confirm_pause_shop' => $this->handleConfirmShopStatus($conversation, $contact, false, $gateway),
            'submit' => $this->onboardingService->submitApplication($conversation, $contact, OnboardingSession::find($conversation->onboarding_session_id), $gateway),
            'edit' => $this->handleEditApplication($conversation, $contact, $gateway),
            'cancel' => $this->handleCancelApplication($conversation, $contact, $gateway),
            default => $gateway->sendTextMessage($contact->phone_number, "I didn't understand that option. Please try again or type *Help*."),
        };
    }

    /**
     * Handle list selection responses.
     */
    public function handleListResponse(WhatsAppConversation $conversation, WhatsAppContact $contact, string $selectionId, string $selectionTitle, WhatsAppGateway $gateway): void
    {
        $step = $conversation->current_step;

        $onboardingListSteps = ['module_selection', 'zone_selection', 'pickup_zone_selection', 'category_selection', 'operating_hours', 'delivery_time', 'business_plan', 'subscription_package', 'kyc_documents'];
        if (in_array($step, $onboardingListSteps) || str_starts_with($selectionId, 'mod_') || str_starts_with($selectionId, 'zone_') || str_starts_with($selectionId, 'pickup_zone_') || str_starts_with($selectionId, 'pkg_') || str_starts_with($selectionId, 'hours_') || str_starts_with($selectionId, 'deliv_')) {
            $message = new WhatsAppMessage();
            $message->type = 'interactive';
            $message->content = [
                'interactive' => [
                    'list_reply' => ['id' => $selectionId, 'title' => $selectionTitle],
                ],
            ];
            $this->onboardingService->processStep($conversation, $contact, $message, $gateway);
            return;
        }

        $editMap = [
            'edit_business_basics' => 'business_basics',
            'edit_module' => 'module_selection',
            'edit_owner_info' => 'owner_info',
            'edit_category' => 'module_selection',
            'edit_location' => 'location',
            'edit_zone' => 'zone_selection',
            'edit_hours' => 'operating_hours',
            'edit_delivery' => 'delivery_time',
            'edit_contact' => 'contact_info',
            'edit_password' => 'account_password',
            'edit_branding' => 'store_branding',
            'edit_cover' => 'cover_branding',
            'edit_plan' => 'business_plan',
            'edit_terms' => 'terms_acceptance',
            'edit_privacy' => 'privacy_acceptance',
            'edit_kyc' => 'kyc_documents',
            'edit_documents' => 'kyc_documents',
        ];

        if (str_starts_with($selectionId, 'edit_cat_')) {
            $this->handleEditCategorySelection($conversation, $contact, $selectionId, $gateway);
            return;
        }

        if (isset($editMap[$selectionId])) {
            $this->handleEditSection($conversation, $contact, $editMap[$selectionId], $gateway);
            return;
        }

        // Delegate other list actions (dashboard options, edit sections, etc.) to button handler
        $this->handleButtonResponse($conversation, $contact, $selectionId, $gateway);
    }

    /**
     * Handle flow responses.
     */
    public function handleFlowResponse(WhatsAppConversation $conversation, WhatsAppContact $contact, array $flowResponse, WhatsAppGateway $gateway): void
    {
        $session = OnboardingSession::find($conversation->onboarding_session_id);

        if (!$session) {
            return;
        }

        $responseData = json_decode($flowResponse['nfm_reply']['response_json'] ?? '{}', true);

        // Map flow screen data to onboarding steps
        $screen = $flowResponse['nfm_reply']['screen'] ?? '';

        $this->onboardingService->processStep($conversation, $contact, (object)[
            'content' => $responseData,
            'type' => 'interactive_flow',
        ], $gateway);
    }

    /**
     * Resume existing onboarding.
     */
    public function resumeOnboarding(WhatsAppConversation $conversation, WhatsAppContact $contact, WhatsAppGateway $gateway): void
    {
        $session = OnboardingSession::find($conversation->onboarding_session_id)
            ?? OnboardingSession::where('contact_id', $contact->id)
                ->where('status', '!=', 'submitted')
                ->where('status', '!=', 'approved')
                ->where('status', '!=', 'rejected')
                ->where('expires_at', '>', now())
                ->latest()
                ->first();

        if ($session && $session->canResume()) {
            $currentStep = $session->current_step ?? 'business_basics';

            $conversation->update([
                'onboarding_session_id' => $session->id,
                'state' => 'onboarding_active',
                'current_step' => $currentStep,
            ]);

            $gateway->sendTextMessage(
                $contact->phone_number,
                "Welcome back! Let's continue from **{$currentStep}**."
            );

            $this->onboardingService->sendStepPrompt($conversation, $contact, $currentStep, $gateway);
        } else {
            $gateway->sendTextMessage(
                $contact->phone_number,
                "Your previous session has expired. Let's start fresh."
            );
            $this->startFreshOnboarding($conversation, $contact, $gateway);
        }
    }

    /**
     * Start fresh onboarding (clear old session).
     */
    public function startFreshOnboarding(WhatsAppConversation $conversation, WhatsAppContact $contact, WhatsAppGateway $gateway): void
    {
        // Expire old sessions
        OnboardingSession::where('contact_id', $contact->id)
            ->where('status', '!=', 'submitted')
            ->update(['status' => 'abandoned']);

        $this->startOnboarding($conversation, $contact, $gateway);
    }

    /**
     * Show selling information.
     */
    public function showSellingInfo(WhatsAppConversation $conversation, WhatsAppContact $contact, WhatsAppGateway $gateway): void
    {
        $gateway->sendTextMessage(
            $contact->phone_number,
            "📖 *About Selling on MyTijaara*\n\n" .
            "MyTijaara helps you reach thousands of customers in your city.\n\n" .
            "*Benefits:*\n" .
            "✅ Free to join (commission-based)\n" .
            "✅ No upfront costs\n" .
            "✅ Marketing & delivery support\n" .
            "✅ Real-time order management\n" .
            "✅ Weekly payouts\n\n" .
            "*Requirements:*\n" .
            "• Valid business registration\n" .
            "• Physical location in our service area\n" .
            "• Phone number for verification\n\n" .
            "Ready to start? Tap *Open My Shop* or reply *Register*! 🚀"
        );
    }

    /**
     * Initiate human handoff.
     */
    public function initiateHumanHandoff(WhatsAppConversation $conversation, WhatsAppContact $contact, WhatsAppGateway $gateway): void
    {
        $support = app(SupportCaseService::class);
        if (!$support->getActiveCase($contact)) {
            $support->createCase($contact, 'WhatsApp support request', 'general', 'medium', null, $conversation);
        }
        $conversation->update(['state' => 'human_handoff']);

        $this->handleHumanHandoff($conversation, $contact, new WhatsAppMessage(), $gateway);
    }

    public function handleRejectedApplicant(WhatsAppConversation $conversation, WhatsAppContact $contact, WhatsAppGateway $gateway): void
    {
        $conversation->update(['state' => 'welcome', 'current_step' => null]);
        $reason = $contact->vendor?->rejection_note ?: 'Please contact support to review the application details.';
        $gateway->sendButtonMessage($contact->phone_number,
            "Your vendor application was not approved.\n\nReason: {$reason}\n\nYou can still chat with us. Tap Talk to Support to request help or discuss corrections.",
            [['id' => 'talk_support', 'title' => 'Talk to Support']]);
    }

    /**
     * Check vendor application status.
     */
    public function checkApplicationStatus(WhatsAppConversation $conversation, WhatsAppContact $contact, WhatsAppGateway $gateway): void
    {
        if ($contact->isVendor() && $contact->vendor) {
            $vendor = $contact->vendor;
            $store = $vendor->store;
            $statusText = (int) $vendor->status === 1 ? 'Approved & Active ✅' : 'Pending Admin Approval ⏳';
            $gateway->sendTextMessage(
                $contact->phone_number,
                "📋 *Your Vendor Application Status:*\n\n" .
                "• Store: *" . ($store?->name ?? 'Your Store') . "*\n" .
                "• Status: *{$statusText}*\n\n" .
                ((int) $vendor->status === 1
                    ? "Your shop is ready! Type *Manage Shop* to view orders and products."
                    : "Your application is under review by our admin team. You will receive a WhatsApp message as soon as it is approved!")
            );
            return;
        }

        $session = OnboardingSession::where('contact_id', $contact->id)->latest()->first();
        if (!$session) {
            $gateway->sendTextMessage(
                $contact->phone_number,
                "You don't have an active vendor application yet.\n\nType *Register* or tap *Open My Shop* to get started in minutes!"
            );
            return;
        }

        $statusMap = [
            'started' => 'Draft / In Progress 📝',
            'submitted' => 'Submitted — Pending Admin Review ⏳',
            'approved' => 'Approved ✅',
            'rejected' => 'Rejected ❌',
        ];

        $statusStr = $statusMap[$session->status] ?? ucfirst($session->status);
        $updatedAt = $session->updated_at ? $session->updated_at->format('M d, Y H:i') : 'Recently';
        $gateway->sendTextMessage(
            $contact->phone_number,
            "📋 *Your Application Status:*\n\n" .
            "• Status: *{$statusStr}*\n" .
            "• Last Updated: {$updatedAt}\n\n" .
            ($session->status === 'started'
                ? "You have an unfinished application. Reply *Continue* to finish setting up your shop!"
                : "We are reviewing your details. We will notify you once approved!")
        );
    }

    /**
     * Handle confirmation of shop status change (open or pause).
     */
    public function handleConfirmShopStatus(WhatsAppConversation $conversation, WhatsAppContact $contact, bool $activate, WhatsAppGateway $gateway): void
    {
        $store = Store::where('vendor_id', $contact->vendor_id)->first();
        if (!$store) {
            $gateway->sendTextMessage($contact->phone_number, "No store found.");
            return;
        }

        app(PendingActionService::class)->prepareAvailability($contact->id, $conversation->id, $store->id, $activate);
    }

    /**
     * Handle add products request.
     */
    protected function handleAddProducts(WhatsAppConversation $conversation, WhatsAppContact $contact, WhatsAppGateway $gateway): void
    {
        $gateway->sendTextMessage(
            $contact->phone_number,
            "📸 *Add New Product*\n\n" .
            "Send me a photo of your product and I'll help you create the listing!\n\n" .
            "Just send a photo and I'll extract:\n" .
            "• Product name\n" .
            "• Category suggestion\n" .
            "• Description draft\n\n" .
            "Then I'll ask for the price and any variations."
        );
    }

    /**
     * Handle view orders request.
     */
    protected function handleViewOrders(WhatsAppConversation $conversation, WhatsAppContact $contact, WhatsAppGateway $gateway): void
    {
        // This would call an AI tool to fetch orders
        RunVendorAiConversation::dispatch($conversation, $contact, new WhatsAppMessage([
            'content' => ['text' => 'Show me my orders today'],
            'type' => 'text',
        ]))->onQueue(config('whatsapp-vendor-concierge.queue.jobs.run_ai_conversation'));
    }

    /**
     * Handle view sales request.
     */
    protected function handleViewSales(WhatsAppConversation $conversation, WhatsAppContact $contact, WhatsAppGateway $gateway): void
    {
        RunVendorAiConversation::dispatch($conversation, $contact, new WhatsAppMessage([
            'content' => ['text' => 'How much did I sell today?'],
            'type' => 'text',
        ]))->onQueue(config('whatsapp-vendor-concierge.queue.jobs.run_ai_conversation'));
    }

    /**
     * Handle shop status toggle.
     */
    protected function handleShopStatusToggle(WhatsAppConversation $conversation, WhatsAppContact $contact, WhatsAppGateway $gateway): void
    {
        $store = Store::where('vendor_id', $contact->vendor_id)->first();

        if (!$store) {
            $gateway->sendTextMessage($contact->phone_number, "No shop found.");
            return;
        }

        $newStatus = !$store->active;
        $action = $newStatus ? 'open' : 'pause';

        $gateway->sendButtonMessage(
            $contact->phone_number,
            "Are you sure you want to *{$action}* your shop?\n\n" .
            "Current status: " . ($store->active ? '🟢 Open' : '🔴 Closed') . "\n" .
            "New status: " . ($newStatus ? '🟢 Open' : '🔴 Closed'),
            [
                ['id' => "confirm_{$action}_shop", 'title' => "✅ Yes, {$action} it"],
                ['id' => 'cancel', 'title' => '❌ Cancel'],
            ],
            'Confirm Shop Status Change'
        );
    }

    /**
     * Handle edit application request.
     */
    public function handleEditApplication(WhatsAppConversation $conversation, WhatsAppContact $contact, WhatsAppGateway $gateway): void
    {
        $session = OnboardingSession::find($conversation->onboarding_session_id);

        if ($session) {
            // Show categorized options to stay well within Meta's 10-row limit
            $gateway->sendListMessage(
                $contact->phone_number,
                "Which category of your application would you like to edit?",
                [[
                    'title' => 'Application Sections',
                    'rows' => [
                        ['id' => 'edit_cat_business', 'title' => '🏪 Business Identity', 'description' => 'Shop name, module, logo'],
                        ['id' => 'edit_cat_location', 'title' => '📍 Location & Schedule', 'description' => 'Address, zone, hours, delivery'],
                        ['id' => 'edit_cat_security', 'title' => '👤 Owner & Security', 'description' => 'Owner name, email, password'],
                        ['id' => 'edit_cat_legal', 'title' => '📜 Plans & Compliance', 'description' => 'Plan, terms, privacy, KYC'],
                    ],
                ]],
                'Edit Application',
                'Select a category to view fields'
            );
        }
    }

    /**
     * Handle category selection when editing an application.
     */
    public function handleEditCategorySelection(WhatsAppConversation $conversation, WhatsAppContact $contact, string $catId, WhatsAppGateway $gateway): void
    {
        $categoryMenus = [
            'edit_cat_business' => [
                'body' => "Which business detail would you like to update?",
                'rows' => [
                    ['id' => 'edit_business_basics', 'title' => '🏪 Shop Name', 'description' => 'Update store / business name'],
                    ['id' => 'edit_module', 'title' => '📦 Business Module', 'description' => 'Grocery, Food, Pharmacy, etc.'],
                    ['id' => 'edit_branding', 'title' => '🖼️ Store Logo', 'description' => 'Upload 1:1 store logo'],
                    ['id' => 'edit_cover', 'title' => '🏞️ Cover Photo', 'description' => 'Upload or skip optional store cover'],
                ],
            ],
            'edit_cat_location' => [
                'body' => "Which location detail would you like to update?",
                'rows' => [
                    ['id' => 'edit_location', 'title' => '📍 Store Address', 'description' => 'Update physical address or GPS pin'],
                    ['id' => 'edit_zone', 'title' => '🌐 Business Zone', 'description' => 'Update operational zone'],
                    ['id' => 'edit_hours', 'title' => '🕒 Operating Hours', 'description' => 'Update open days and store hours'],
                    ['id' => 'edit_delivery', 'title' => '🚚 Delivery Time', 'description' => 'Update estimated delivery duration'],
                ],
            ],
            'edit_cat_security' => [
                'body' => "Which account detail would you like to update?",
                'rows' => [
                    ['id' => 'edit_owner_info', 'title' => '👤 Owner Name', 'description' => 'Update your full name'],
                    ['id' => 'edit_contact', 'title' => '📧 Email & Contact', 'description' => 'Update email and phone number'],
                    ['id' => 'edit_password', 'title' => '🔐 Dashboard Password', 'description' => 'Generate secure link to update password'],
                ],
            ],
            'edit_cat_legal' => [
                'body' => "Which policy or document would you like to update?",
                'rows' => [
                    ['id' => 'edit_plan', 'title' => '💼 Business Plan', 'description' => 'Commission or Subscription'],
                    ['id' => 'edit_terms', 'title' => '📜 Terms & Conditions', 'description' => 'View and accept vendor terms'],
                    ['id' => 'edit_privacy', 'title' => '🔒 Privacy Policy', 'description' => 'View and accept privacy policy'],
                    ['id' => 'edit_kyc', 'title' => '📑 KYC Verification', 'description' => 'TIN, CAC RC/BN, or NIN verification'],
                ],
            ],
        ];

        $menu = $categoryMenus[$catId] ?? null;
        if (!$menu) {
            $this->handleEditApplication($conversation, $contact, $gateway);
            return;
        }

        $gateway->sendListMessage(
            $contact->phone_number,
            $menu['body'],
            [[
                'title' => 'Select Field',
                'rows' => $menu['rows'],
            ]],
            'Edit Field',
            'Select an option above to edit'
        );
    }

    /**
     * Handle editing a specific onboarding section.
     */
    public function handleEditSection(WhatsAppConversation $conversation, WhatsAppContact $contact, string $targetStep, WhatsAppGateway $gateway): void
    {
        $session = OnboardingSession::find($conversation->onboarding_session_id);

        if (!$session) {
            $gateway->sendTextMessage($contact->phone_number, "No active application found to edit. Reply *Start* to begin a new application.");
            return;
        }

        $conversation->update([
            'state' => 'onboarding_active',
            'current_step' => $targetStep,
            'onboarding_session_id' => $session->id,
        ]);

        $session->update([
            'current_step' => $targetStep,
            'last_activity_at' => now(),
        ]);

        $session->updateData(['_in_review' => true]);

        $this->onboardingService->sendStepPrompt($conversation, $contact, $targetStep, $gateway);
    }

    /**
     * Handle cancel application.
     */
    public function handleCancelApplication(WhatsAppConversation $conversation, WhatsAppContact $contact, WhatsAppGateway $gateway): void
    {
        $session = OnboardingSession::find($conversation->onboarding_session_id);

        if ($session) {
            $session->update(['status' => 'abandoned']);
            OnboardingEvent::log($session->id, $contact->id, 'onboarding_abandoned', $session->current_step);
        }

        $conversation->update(['state' => 'welcome']);

        $gateway->sendTextMessage(
            $contact->phone_number,
            "Application cancelled. You can start a new one anytime by saying \"I want to sell on MyTijaara\"."
        );
    }

    /**
     * Notify support team of handoff.
     */
    protected function notifySupportTeam(WhatsAppConversation $conversation, WhatsAppContact $contact): void
    {
        Log::info('Human handoff requested', [
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'vendor_id' => $contact->vendor_id,
        ]);

        try {
            $adminEmail = config('mail.from.address') ?? 'admin@mytijaara.com';
            $businessSettingEmail = \App\Models\BusinessSetting::where('key', 'email_address')->first()?->value;
            if ($businessSettingEmail && filter_var($businessSettingEmail, FILTER_VALIDATE_EMAIL)) {
                $adminEmail = $businessSettingEmail;
            }

            \Illuminate\Support\Facades\Mail::to($adminEmail)
                ->send(new \Modules\WhatsAppVendorConcierge\app\Mail\VendorSupportEscalationMail($conversation, $contact));

            Log::info("Support escalation email dispatched to {$adminEmail} for conversation #{$conversation->id}");
        } catch (\Throwable $e) {
            Log::warning("Failed to dispatch support escalation email: " . $e->getMessage());
        }
    }
}
