<?php

namespace Modules\WhatsAppVendorConcierge\tests\Hardening;

use Illuminate\Support\Facades\Queue;
use Modules\WhatsAppVendorConcierge\app\Jobs\ProcessIncomingWhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Jobs\ProcessWhatsAppStatus;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;
use PHPUnit\Framework\Attributes\Test;

class ReceiptSecurityTest extends HardeningTestCase
{
    #[Test]
    public function signed_batched_status_webhook_only_dispatches_receipt_jobs(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../fixtures/statuses.json'), true);
        $body = json_encode($fixture);
        $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'test-secret'),
        ], $body)->assertOk();
        Queue::assertPushed(ProcessWhatsAppStatus::class, 3);
        Queue::assertNotPushed(ProcessIncomingWhatsAppMessage::class);
        $this->assertSame(0, WhatsAppContact::count());
    }

    #[Test]
    public function receipts_are_idempotent_order_independent_and_minimize_errors(): void
    {
        [, , $conversation] = $this->application();
        $message = WhatsAppMessage::create([
            'conversation_id' => $conversation->id, 'whatsapp_message_id' => 'wamid.outbound',
            'direction' => 'outbound', 'type' => 'text', 'status' => 'sent',
        ]);
        $base = ['id' => 'wamid.outbound', 'timestamp' => '1750000000'];
        foreach (['read', 'delivered', 'sent', 'read'] as $status) {
            (new ProcessWhatsAppStatus($base + ['status' => $status]))->handle();
        }
        $this->assertSame('read', $message->fresh()->status);
        $this->assertSame(1750000000, $message->fresh()->read_at->timestamp);
        (new ProcessWhatsAppStatus($base + ['status' => 'failed', 'errors' => [['code' => 131047, 'message' => 'private text']]]))->handle();
        $this->assertSame('read', $message->fresh()->status);
        $this->assertSame(['codes' => [131047]], $message->fresh()->error);
        $this->assertStringNotContainsString('private text', $message->fresh()->toJson());
        (new ProcessWhatsAppStatus(['id' => 'unknown', 'timestamp' => '1750000000', 'status' => 'sent']))->handle();
        $this->assertSame(1, WhatsAppMessage::count());
    }

    #[Test]
    public function newer_same_state_receipts_are_retained_while_older_replays_are_ignored(): void
    {
        [, , $conversation] = $this->application();
        $message = WhatsAppMessage::create([
            'conversation_id' => $conversation->id, 'whatsapp_message_id' => 'wamid.timestamp',
            'direction' => 'outbound', 'type' => 'text', 'status' => 'sent',
        ]);

        $message->applyReceipt(['status' => 'delivered', 'timestamp' => '1750000001']);
        $message->applyReceipt(['status' => 'delivered', 'timestamp' => '1750000003']);
        $message->applyReceipt(['status' => 'delivered', 'timestamp' => '1750000002']);

        $this->assertSame(1750000003, $message->fresh()->delivered_at->timestamp);
        $this->assertSame(1750000003, $message->fresh()->metadata['receipt_timestamps']['delivered']);
    }

    #[Test]
    public function signature_and_verification_fail_closed(): void
    {
        $this->postJson('/webhooks/whatsapp', ['entry' => []])->assertUnauthorized();
        $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=test-verify&hub_challenge=123')->assertOk()->assertSee('123');
        config(['whatsapp-vendor-concierge.webhook.verify_token' => null]);
        $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_challenge=123')->assertForbidden();
    }
}
