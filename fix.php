<?php
$c = file_get_contents('Modules/WhatsAppVendorConcierge/app/Services/VendorOnboardingService.php');
$target = <<<EOT
        if (!\$validation['valid']) {
            \$this->sendValidationErrors(\$conversation, \$contact, \$validation['errors'], \$gateway);
EOT;
$replacement = <<<EOT
        if (!\$validation['valid']) {
            if (!isset(\$message->is_ai_extracted)) {
                \$orchestrator = app(\Modules\WhatsAppVendorConcierge\app\Services\ConversationOrchestrator::class);
                if (\$orchestrator->handleOnboardingExtraction(\$conversation, \$contact, \$message, \$gateway, \$step, \$validation['errors'])) {
                    return;
                }
            }

            \$this->sendValidationErrors(\$conversation, \$contact, \$validation['errors'], \$gateway);
EOT;
$c = str_replace($target, $replacement, $c);
file_put_contents('Modules/WhatsAppVendorConcierge/app/Services/VendorOnboardingService.php', $c);
