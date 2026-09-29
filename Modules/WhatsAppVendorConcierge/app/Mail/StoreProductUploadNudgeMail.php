<?php

namespace Modules\WhatsAppVendorConcierge\app\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class StoreProductUploadNudgeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public array $details
    ) {}

    public function build(): self
    {
        return $this->subject('🚀 MyTijaara Launches in 3 Days — Upload Your Products For FREE Now!')
            ->view('whatsappvendorconcierge::emails.store-product-upload-nudge', [
                'd' => $this->details,
            ]);
    }
}
