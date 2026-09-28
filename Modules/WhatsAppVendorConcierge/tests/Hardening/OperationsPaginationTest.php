<?php

namespace Modules\WhatsAppVendorConcierge\tests\Hardening;

use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Services\Operations\ConciergeDiagnosticService;

class OperationsPaginationTest extends HardeningTestCase
{
    public function test_default_operations_page_paginates_before_it_diagnoses_rows(): void
    {
        foreach (range(1, 30) as $number) {
            $contact = WhatsAppContact::create(['whatsapp_id' => 'page-'.$number, 'phone_number' => '234800'.str_pad((string) $number, 6, '0', STR_PAD_LEFT), 'display_name' => 'Vendor '.$number]);
            WhatsAppConversation::create(['contact_id' => $contact->id, 'state' => 'welcome', 'last_activity_at' => now()->subMinutes($number)]);
        }
        $page = app(ConciergeDiagnosticService::class)->paginateAll('Vendor', 25);
        $this->assertSame(30, $page->total());
        $this->assertCount(25, $page->items());
        $this->assertSame('Vendor 1', $page->items()[0]['diag']['name']);
        $this->assertSame('Vendor 30', app(ConciergeDiagnosticService::class)->paginateAll('Vendor 30', 25)->items()[0]['diag']['name']);
    }
}
