<?php
namespace Modules\WhatsAppVendorConcierge\tests\Hardening;
use Modules\WhatsAppVendorConcierge\app\Jobs\ProcessIncomingWhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Models\{WhatsAppMessage, WhatsAppContact, WhatsAppConversation, InboundReceipt, ResumeCampaign};
use Modules\WhatsAppVendorConcierge\app\Services\{WhatsAppGateway, VendorOnboardingService, ConversationManager};
class CheckpointProbeJob extends ProcessIncomingWhatsAppMessage {
    public bool $failBefore = false;
    public bool $failAfter = false;
    public int $actions = 0;
    protected function handleMedia(WhatsAppMessage $message, array $data, WhatsAppGateway $gateway): void {
        if ($this->failBefore) throw new \RuntimeException('Injected pre-processing failure');
    }
    protected function processByState(WhatsAppConversation $conversation, WhatsAppContact $contact, WhatsAppMessage $message, VendorOnboardingService $onboarding, ConversationManager $manager, WhatsAppGateway $gateway): void {
        $this->actions++;
        $gateway->sendTextMessage($contact->phone_number, 'Saved');
        if ($this->failAfter) throw new \RuntimeException('Injected ambiguous post-send failure');
    }
}
class InboundCheckpointTest extends HardeningTestCase {
    private function runJob(CheckpointProbeJob $job, WhatsAppGateway $gateway): void { $job->handle($gateway, app(VendorOnboardingService::class), app(ConversationManager::class)); }
    private function fixture(): array {
        [$contact,$session,$conversation] = $this->application();
        $conversation->update(['current_step'=>'business_name']);
        $session->update(['current_step'=>'business_name']);
        $job = new CheckpointProbeJob(['id'=>'checkpoint-test','from'=>$contact->whatsapp_id,'type'=>'image','image'=>['id'=>'media-1']], []);
        $gateway = \Mockery::mock(WhatsAppGateway::class);
        $gateway->shouldReceive('markAsRead')->andReturn([]);
        return [$job,$gateway,$conversation];
    }
    public function test_retry_after_receipt_reuses_message_and_runs_action_once(): void {
        [$job,$gateway] = $this->fixture();
        $gateway->shouldReceive('sendTextMessage')->once()->andReturn([]);
        $job->failBefore = true;
        try { $this->runJob($job,$gateway); $this->fail('Expected failure'); } catch (\RuntimeException) {}
        $this->assertSame('received', InboundReceipt::first()->phase);
        $job->failBefore = false;
        $this->runJob($job,$gateway);
        $this->runJob($job,$gateway);
        $this->assertSame(1, WhatsAppMessage::where('direction','inbound')->count());
        $this->assertSame(1, $job->actions);
        $this->assertSame('completed', InboundReceipt::first()->phase);
    }
    public function test_ambiguous_post_send_failure_never_reexecutes_action(): void {
        [$job,$gateway] = $this->fixture();
        $gateway->shouldReceive('sendTextMessage')->once()->andReturn([]);
        $job->failAfter = true;
        try { $this->runJob($job,$gateway); $this->fail('Expected failure'); } catch (\RuntimeException) {}
        $this->runJob($job,$gateway);
        $this->assertSame(1,$job->actions);
        $this->assertSame('needs_review',InboundReceipt::first()->phase);
        $this->artisan('whatsapp:replay-inbound',['message_id'=>WhatsAppMessage::first()->id,'--execute'=>true])->assertFailed();
        $this->artisan('whatsapp:review-inbound',['message_id'=>WhatsAppMessage::first()->id,'--execute'=>true,'--reason'=>'Checked the sent reply; no further action is needed.'])->assertSuccessful();
        $this->assertSame('reviewed', InboundReceipt::first()->phase);
        $this->runJob($job,$gateway);
        $this->assertSame(1,$job->actions);
        $this->assertSame(1,\Modules\WhatsAppVendorConcierge\app\Models\ConciergeRecoveryAudit::where('action','acknowledge_inbound')->count());
    }
    public function test_changed_conversation_blocks_old_answer(): void {
        [$job,$gateway,$conversation] = $this->fixture();
        $job->failBefore = true;
        try { $this->runJob($job,$gateway); } catch (\RuntimeException) {}
        $conversation->update(['current_step'=>'location']);
        $job->failBefore = false;
        $this->runJob($job,$gateway);
        $this->assertSame(0,$job->actions);
        $this->assertSame('ConversationAdvanced',InboundReceipt::first()->error_type);
    }
    public function test_campaign_launch_does_not_claim_sends_or_dispatch_jobs(): void {
        $campaign=ResumeCampaign::create(['name'=>'Draft','meta_template_name'=>'resume','status'=>'draft','sent_count'=>0,'audience_criteria'=>[]]);
        $response=app(\Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\ResumeCampaignController::class)->launch($campaign);
        $this->assertTrue($response->isRedirect());
        $this->assertSame('draft',$campaign->fresh()->status);
        $this->assertEquals(0,$campaign->fresh()->sent_count);
        \Illuminate\Support\Facades\Queue::assertNothingPushed();
    }
    public function test_busy_contact_releases_job_without_consuming_message_and_can_resume(): void {
        [$job,$gateway,$conversation]=$this->fixture();
        $gateway->shouldReceive('sendTextMessage')->once()->andReturn([]);
        $lock=\Illuminate\Support\Facades\Cache::lock('wa_inbound_contact_'.$conversation->contact_id,150);
        $this->assertTrue($lock->get());
        $queueJob=\Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $queueJob->shouldReceive('release')->once()->with(15);
        $job->setJob($queueJob);
        $this->runJob($job,$gateway);
        $this->assertSame(0,$job->actions);
        $this->assertSame(0,WhatsAppMessage::count());
        $this->assertGreaterThan(now()->addMinutes(9)->timestamp,$job->retryUntil()->getTimestamp());
        $lock->release();
        $this->runJob($job,$gateway);
        $this->assertSame(1,$job->actions);
        $this->assertSame('completed',InboundReceipt::first()->phase);
    }

}
