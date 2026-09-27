<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;
use Illuminate\Support\Facades\Log;
use Modules\WhatsAppVendorConcierge\app\Agents\OrchestratorAgent;
use Illuminate\Support\Facades\Cache;

class ConversationOrchestrator
{
    public function __construct(
        protected ConversationManager $manager,
        protected AiFallbackService $aiService
    ) {}

    public function routeMessage(
        WhatsAppConversation $conversation,
        WhatsAppContact $contact,
        WhatsAppMessage $message,
        WhatsAppGateway $gateway
    ): bool {
        $text = (string) ($message->raw_text ?? '');
        $text = trim($text);

        if ($text === '') {
            return false;
        }

        if (app(ShopQuickActionService::class)->handle($text, $conversation, $contact, $gateway)) return true;

        // Product answers must not be reclassified by the general AI router.
        if ($contact->vendor_id && $conversation->state === 'ai_active'
            && !in_array(ConversationCommands::action($text), ['support','shop_details','manage_shop','status','product_status','shop_readiness','preferences'], true)
            && app(ProductListingFlow::class)->handles($conversation,$text)) {
            $reply=app(ProductListingFlow::class)->receiveOrDefer($conversation,$contact,$message);
            if ($reply !== '') app(ProductListingFlow::class)->sendReply($gateway,$contact->phone_number,$reply,$conversation);
            return true;
        }

        // Deterministic routing first
        $command = ConversationCommands::action($text);
        
        if ($command !== null) {
            if ($command === 'preferences') $this->manager->showNotificationPreferences($conversation, $contact, $gateway, $text);
            else $this->executeAction($command, $conversation, $contact, $gateway);
            return true;
        }

        if (in_array(mb_strtolower($text), ['thanks','thank you','thank you!','thanks!'],true)) {
            $gateway->sendButtonMessage($contact->phone_number,"You're welcome! 😊 Your progress is saved. What would you like to do next?",[
                ['id'=>$conversation->isOnboarding()?'resume_onboarding':'manage_shop','title'=>$conversation->isOnboarding()?'Continue application':'Manage my shop'],
                ['id'=>'talk_support','title'=>'Talk to Support'],
            ]);
            return true;
        }
        // Ordinary answers belong to the current form, not an AI navigation guess.
        if ($conversation->isOnboarding() || ($conversation->onboarding_session_id && $conversation->current_step)) return false;

        // Loop detection
        $cacheKey = 'wa_orchestrator_loop_' . $contact->phone_number;
        $history = Cache::get($cacheKey, []);
        $history[] = $text;
        if (count($history) > 3) {
            array_shift($history);
        }
        Cache::put($cacheKey, $history, now()->addMinutes(10));

        if (count($history) === 3 && count(array_unique($history)) === 1) {
            // User sent the exact same unhandled message 3 times
            $this->manager->initiateHumanHandoff($conversation, $contact, $gateway);
            return true;
        }

        if (strlen($text) > 2) {
            return $this->invokeAiOrchestrator($text, $conversation, $contact, $gateway);
        }

        return false;
    }

    protected function executeAction(string $action, WhatsAppConversation $conversation, WhatsAppContact $contact, WhatsAppGateway $gateway): void
    {
        match ($action) {
            'preferences' => $this->manager->showNotificationPreferences($conversation, $contact, $gateway, 'alerts'),
            'restart', 'register', 'start_onboarding' => $this->manager->startFreshOnboarding($conversation, $contact, $gateway),
            'welcome' => $this->manager->handleWelcome($conversation, $contact, $gateway),
            'support', 'escalate_to_human' => $this->manager->initiateHumanHandoff($conversation, $contact, $gateway),
            'info', 'show_faq' => $this->manager->showSellingInfo($conversation, $contact, $gateway),
            'help' => $this->manager->showHelp($conversation, $contact, $gateway),
            'manage_shop' => $this->manager->showManageShop($conversation, $contact, $gateway),
            'product_status' => $this->manager->showProductStatuses($conversation, $contact, $gateway),
            'shop_readiness' => $this->manager->showShopReadiness($conversation, $contact, $gateway),
            'shop_details' => $this->manager->showShopDetails($conversation, $contact, $gateway),
            'status', 'check_status' => $this->manager->checkApplicationStatus($conversation, $contact, $gateway),
            'resume', 'resume_onboarding' => $this->manager->resumeOnboarding($conversation, $contact, $gateway),
            'resend' => $this->manager->handleButtonResponse($conversation, $contact, 'resend_password_link', $gateway),
            'unknown_intent' => null, // Handled outside
            default => $this->manager->handleWelcome($conversation, $contact, $gateway),
        };
    }

    protected function invokeAiOrchestrator(
        string $text,
        WhatsAppConversation $conversation,
        WhatsAppContact $contact,
        WhatsAppGateway $gateway
    ): bool {
        try {
            $agent = new OrchestratorAgent();
            $result = $this->aiService->promptAgent($agent, $text);
            $response = $result['response'];
            
            $content = (string) $response->text;
            Log::info('AI Orchestrator response', ['conversation_id' => $conversation->id, 'response_length' => mb_strlen($content)]);
            
            if (preg_match('/```json\s*(\{.*?\})\s*```/s', $content, $matches)) {
                $content = $matches[1];
            } else if (preg_match('/(\{.*?\})/s', $content, $matches)) {
                $content = $matches[1];
            }

            $decision = json_decode($content, true);
            
            if (is_array($decision) && isset($decision['requested_tool'])) {
                $confidence = (float) ($decision['confidence'] ?? 0);
                $tool = $decision['requested_tool'];
                
                if ($confidence > 0.7 && $tool !== 'unknown_intent') {
                    $this->executeAction($tool, $conversation, $contact, $gateway);
                    return true;
                }
            }
            
            return false;
            
        } catch (\Exception $e) {
            Log::error('AI Orchestration failed', ['exception' => get_class($e), 'conversation_id' => $conversation->id]);
            return false;
        }
    }

    public function handleOnboardingExtraction(
        WhatsAppConversation $conversation,
        WhatsAppContact $contact,
        WhatsAppMessage $message,
        WhatsAppGateway $gateway,
        string $currentStep,
        array $validationErrors
    ): bool {
        // These steps use deterministic handling and must never enter extraction prompts.
        if (in_array($currentStep, ['account_password', 'kyc_documents'], true)) return false;
        $text = (string) ($message->raw_text ?? '');
        if (trim($text) === '' || str_starts_with($text, '[redacted:')) {
            return false;
        }

        try {
            $agent = new \Modules\WhatsAppVendorConcierge\app\Agents\OnboardingExtractionAgent($currentStep, $validationErrors);
            $result = $this->aiService->promptAgent($agent, $text);
            $response = $result['response'];
            
            $content = (string) $response->text;
            Log::info('AI Onboarding Extraction response', ['conversation_id' => $conversation->id, 'step' => $currentStep, 'response_length' => mb_strlen($content)]);
            
            if (preg_match('/```json\s*(\{.*?\})\s*```/s', $content, $matches)) {
                $content = $matches[1];
            } else if (preg_match('/(\{.*?\})/s', $content, $matches)) {
                $content = $matches[1];
            }

            $decision = json_decode($content, true);
            
            if (is_array($decision) && isset($decision['requested_tool'])) {
                $tool = $decision['requested_tool'];
                
                if ($tool === 'submit_current_field' && isset($decision['extracted_value'])) {
                    // Update the message raw_text with the cleanly extracted value
                    $message->raw_text = $decision['extracted_value'];
                    $message->is_ai_extracted = true;
                    
                    // Re-run processStep with the clean message
                    $onboardingService = app(\Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService::class);
                    $onboardingService->processStep($conversation, $contact, $message, $gateway);
                    return true;
                }

                if ($tool === 'explain_current_question' && isset($decision['clarification_question'])) {
                    $gateway->sendTextMessage($contact->phone_number, $decision['clarification_question']);
                    // Resend the actual prompt
                    $onboardingService = app(\Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService::class);
                    $onboardingService->sendStepPrompt($conversation, $contact, $currentStep, $gateway);
                    return true;
                }

                if ($tool === 'unrelated_question' && isset($decision['clarification_question'])) {
                    $gateway->sendTextMessage($contact->phone_number, $decision['clarification_question']);
                    $onboardingService = app(\Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService::class);
                    $onboardingService->sendStepPrompt($conversation, $contact, $currentStep, $gateway);
                    return true;
                }
            }
            
            return false;
            
        } catch (\Exception $e) {
            Log::error('AI Onboarding Extraction failed', ['exception' => get_class($e), 'conversation_id' => $conversation->id]);
            return false;
        }
    }
}
