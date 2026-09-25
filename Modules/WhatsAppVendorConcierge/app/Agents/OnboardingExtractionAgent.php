<?php

namespace Modules\WhatsAppVendorConcierge\app\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Messages\SystemMessage;

class OnboardingExtractionAgent implements Agent
{
    use \Laravel\Ai\Promptable;

    public function __construct(
        protected string $currentStep,
        protected array $validationErrors
    ) {}

    public function instructions(): string
    {
        $stepStr = json_encode($this->currentStep);
        $errorsStr = json_encode($this->validationErrors);

        return "You are a helpful data extraction assistant for an ongoing WhatsApp onboarding flow.\n" .
            "The user is currently on step: {$stepStr}.\n" .
            "The deterministic parser rejected their last answer with errors: {$errorsStr}.\n\n" .
            "Your job is to read the user's message and determine what they meant.\n" .
            "Available tools:\n" .
            "- 'submit_current_field': The user naturally answered the question. Extract just the exact value they meant.\n" .
            "- 'explain_current_question': The user is confused and asking what the question means or how to answer.\n" .
            "- 'unrelated_question': The user asked a completely unrelated question (e.g. pricing, fees).\n" .
            "- 'unknown_intent': You have no idea what they mean.\n\n" .
            "Output strictly valid JSON with no markdown formatting. Schema:\n" .
            "{\n" .
            "  \"intent\": \"brief description\",\n" .
            "  \"requested_tool\": \"<one of the tool names above>\",\n" .
            "  \"extracted_value\": \"<the exact value if submit_current_field, else null>\",\n" .
            "  \"clarification_question\": \"<your explanation or answer to their question if explain_current_question or unrelated_question, else null>\"\n" .
            "}";
    }
}
