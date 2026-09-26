<?php
namespace Modules\WhatsAppVendorConcierge\app\Mail;
use Illuminate\Mail\Mailable;
class VendorAccessMail extends Mailable
{
    public function __construct(public array $details) {}
    public function build() {return $this->subject('Your MyTijaara shop: login details and getting started')->view('whatsappvendorconcierge::emails.vendor-access',['d'=>$this->details]);}
}
