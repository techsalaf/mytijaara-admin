<?php

namespace Modules\WhatsAppVendorConcierge\app\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;

class VendorSupportEscalationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public WhatsAppConversation $conversation,
        public WhatsAppContact $contact,
        public array $details = []
    ) {}

    public function build(): self
    {
        $vendor = $this->contact->vendor;
        $vendorName = $vendor ? ($vendor->f_name . ' ' . $vendor->l_name) : ($this->contact->display_name ?? 'Unknown Vendor');
        $storeName = $vendor?->store?->name ?? 'N/A';

        return $this->subject("🚨 WhatsApp Vendor Support Escalation: [Ref #{$this->conversation->id}] {$vendorName}")
            ->html(<<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>WhatsApp Support Escalation</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #00703c; color: white; padding: 15px 20px; border-radius: 6px 6px 0 0;">
        <h2 style="margin: 0;">MyTijaara WhatsApp Support Escalation</h2>
    </div>
    <div style="border: 1px solid #ddd; border-top: none; padding: 20px; border-radius: 0 0 6px 6px;">
        <p>A vendor has requested human support via WhatsApp. Please follow up promptly.</p>
        <table style="width: 100%; border-collapse: collapse; margin: 15px 0;">
            <tr>
                <td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold; width: 35%;">Reference:</td>
                <td style="padding: 8px; border-bottom: 1px solid #eee;">WHATSAPP-{$this->conversation->id}</td>
            </tr>
            <tr>
                <td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">Vendor Name:</td>
                <td style="padding: 8px; border-bottom: 1px solid #eee;">{$vendorName}</td>
            </tr>
            <tr>
                <td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">Store Name:</td>
                <td style="padding: 8px; border-bottom: 1px solid #eee;">{$storeName}</td>
            </tr>
            <tr>
                <td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">WhatsApp Number:</td>
                <td style="padding: 8px; border-bottom: 1px solid #eee;">+{$this->contact->phone_number}</td>
            </tr>
            <tr>
                <td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">Current Step / State:</td>
                <td style="padding: 8px; border-bottom: 1px solid #eee;">{$this->conversation->state}</td>
            </tr>
            <tr>
                <td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">Requested At:</td>
                <td style="padding: 8px; border-bottom: 1px solid #eee;">{$this->conversation->updated_at}</td>
            </tr>
        </table>
        <p style="margin-top: 20px;">
            <a href="https://wa.me/{$this->contact->phone_number}" style="display: inline-block; background-color: #25D366; color: white; padding: 10px 18px; text-decoration: none; border-radius: 4px; font-weight: bold;">Chat on WhatsApp Directly</a>
        </p>
    </div>
</body>
</html>
HTML);
    }
}
