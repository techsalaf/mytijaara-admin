<?php

namespace Modules\WhatsAppVendorConcierge\app\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\WhatsAppVendorConcierge\app\DTOs\FlowSubmission;

class ProcessVendorFlowSubmission implements \Illuminate\Contracts\Queue\ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;

    public int $tries = 3;

    public int $timeout = 90;

    public array $backoff = [10, 30, 60];

    public function __construct(public FlowSubmission $submission) {}

    public function handle(\Modules\WhatsAppVendorConcierge\app\Services\FlowSubmissionProcessor $processor): void
    {
        $result = $processor->process($this->submission);
        if (in_array($result, ['correction_required', 'invalid_submission'], true)) {
            app(\Modules\WhatsAppVendorConcierge\app\Services\FlowMetaClient::class)->text($this->submission->sender, 'We could not accept that submission. Reopen the form to check your details, or ask to continue in chat.');
        }
    }
}
