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

        // Deterministic routing first
        $command = ConversationCommands::action($text);
        
        if ($command !== null) {
            $this->executeAction($command, $conversation, $contact, $gateway);
            return true;
        }

        // We only invoke AI orchestration if they are NOT in an active onboarding step,
        // OR if they are in an active onboarding step but their text matches a known navigation intent via AI.
        // Wait, if they are in onboarding, they might be answering a question (e.g. "Abuja", "5000").
        // We shouldn't send that to the AI orchestrator unless it looks like a question/command.
        // Let's rely on the AI to return `unknown_intent` for normal text, which means we return false
        // and let state-based routing handle it.

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
            'restart', 'register', 'start_onboarding' => $this->manager->startFreshOnboarding($conversation, $contact, $gateway),
            'welcome' => $this->manager->handleWelcome($conversation, $contact, $gateway),
            'support', 'escalate_to_human' => $this->manager->initiateHumanHandoff($conversation, $contact, $gateway),
            'info', 'show_faq' => $this->manager->showSellingInfo($conversation, $contact, $gateway),
            'help' => $this->manager->showHelp($conversation, $contact, $gateway),
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
            Log::info('AI Orchestrator response', ['content' => $content, 'phone' => $contact->phone_number]);
            
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
            Log::error('AI Orchestration failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
