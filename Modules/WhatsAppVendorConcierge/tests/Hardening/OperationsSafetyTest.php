<?php
namespace Modules\WhatsAppVendorConcierge\tests\Hardening;

use GuzzleHttp\{Client, HandlerStack};
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Modules\WhatsAppVendorConcierge\app\Models\{WhatsAppMessage, WhatsAppContact, ConciergeRecoveryAudit};
use Modules\WhatsAppVendorConcierge\app\Services\{WhatsAppGateway, SupportCaseService};
use Modules\WhatsAppVendorConcierge\app\Services\Operations\{ConciergeDiagnosticService, ConciergeRecoveryService};
use PHPUnit\Framework\Attributes\Test;

class OperationsSafetyTest extends ApplicationFixtureTestCase
{
    #[Test]
    public function pending_applicants_cannot_resume_stale_draft_steps(): void
    {
        [$contact,$session,$conversation]=$this->application();
        $vendor=\App\Models\Vendor::create(['phone'=>'23499999','email'=>'pending@example.test','status'=>null]);
        $contact->update(['vendor_id'=>$vendor->id]);
        $session->update(['status'=>'submitted','vendor_id'=>$vendor->id]);
        $manager=\Mockery::mock(\Modules\WhatsAppVendorConcierge\app\Services\ConversationManager::class);
        $manager->shouldReceive('checkApplicationStatus')->once();
        $onboarding=\Mockery::mock(\Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService::class);
        $job=new \Modules\WhatsAppVendorConcierge\app\Jobs\ProcessIncomingWhatsAppMessage([],[]);
        $method=new \ReflectionMethod($job,'processByState');
        $method->invoke($job,$conversation,$contact,new WhatsAppMessage(['type'=>'text','raw_text'=>'Hi','content'=>[]]),$onboarding,$manager,$this->gateway());
        $this->assertSame('onboarding_completed',$conversation->fresh()->state);
        $this->assertNull($conversation->fresh()->current_step);
        $this->assertSame('submitted',$session->fresh()->status);
    }

    #[Test]
    public function a_changed_conversation_invalidates_its_recovery_preview(): void
    {
        [,,$conversation]=$this->application();
        $controller=app(\Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\OperationsCenterController::class);
        $request=\Illuminate\Http\Request::create('/preview','POST',['action'=>'assign_human']);
        $preview=$controller->previewAction($request,$conversation->id)->getData(true);
        $conversation->update(['state'=>'welcome']);
        $request=\Illuminate\Http\Request::create('/execute','POST',['action'=>'assign_human','preview_token'=>$preview['preview_token']]);
        try {
            $controller->executeAction($request,$conversation->id);
            $this->fail('A changed preview must be rejected.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(409,$error->getStatusCode());
        }
        $this->assertSame('welcome',$conversation->fresh()->state);
    }

    private function inbound($conversation): void
    {
        $message=WhatsAppMessage::create(['conversation_id'=>$conversation->id,'whatsapp_message_id'=>'wamid.in','direction'=>'inbound','type'=>'text','raw_text'=>'[redacted: credential step]','status'=>'delivered']);
        $message->created_at=now()->subMinutes(10);$message->save();
    }
    private function gateway(int $status=200): WhatsAppGateway
    {
        $gateway=app(WhatsAppGateway::class);
        $response=$status===200 ? '{"messages":[{"id":"wamid.out"}]}' : '{"error":{"code":132001,"message":"Template unavailable"}}';
        (new \ReflectionProperty($gateway,'client'))->setValue($gateway,new Client(['base_uri'=>'https://graph.facebook.com/v21.0/','handler'=>HandlerStack::create(new MockHandler([new Response($status,[],$response),new Response($status,[],$response)]))]));
        $this->app->instance(WhatsAppGateway::class,$gateway);
        return $gateway;
    }
    #[Test]
    public function delivery_reconciliation_is_audited_idempotent_and_keeps_original_time(): void
    {
        [$contact,,$conversation]=$this->application();$this->inbound($conversation);
        $path=base_path('.agents/test-delivery-history.log');
        if (!is_dir(dirname($path))) mkdir(dirname($path),0775,true);
        $at=now()->subMinutes(5)->format('Y-m-d H:i:s');
        file_put_contents($path,'['.$at.'] live.INFO: WhatsApp message sent '.json_encode(['to'=>$contact->phone_number,'message_id'=>'wamid.historical','type'=>'interactive']).PHP_EOL);
        $service=app(\Modules\WhatsAppVendorConcierge\app\Services\Operations\DeliveryLogReconciler::class);
        $this->assertSame(1,$service->run(true,$path)['eligible']);
        $this->assertSame(0,WhatsAppMessage::where('direction','outbound')->count());
        $this->assertSame(1,$service->run(false,$path)['imported']);
        $message=WhatsAppMessage::where('whatsapp_message_id','wamid.historical')->firstOrFail();
        $this->assertSame($at,$message->created_at->format('Y-m-d H:i:s'));
        $this->assertSame('sent',$message->status);
        $this->assertTrue($message->metadata['content_unavailable']);
        $this->assertSame(0,$service->run(false,$path)['imported']);
        $this->assertSame('unresponsive_user',app(ConciergeDiagnosticService::class)->diagnoseConversation($conversation)['failure_category']);
    }

    #[Test]
    public function inbox_and_operations_views_render_with_real_diagnoses(): void
    {
        [$contact,,$conversation]=$this->application();$this->inbound($conversation);
        $contact->update(['display_name'=>'Demo Shop Owner']);
        $dir=base_path('.agents/operations-view-fixture');
        if (!is_dir($dir.'/layouts/admin')) mkdir($dir.'/layouts/admin',0775,true);
        file_put_contents($dir.'/layouts/admin/app.blade.php', '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="/public/assets/admin/css/bootstrap.min.css"><link rel="stylesheet" href="/public/assets/admin/css/theme.minc619.css"><link rel="stylesheet" href="/public/assets/admin/vendor/icon-set/style.css">@stack("css_or_js")</head><body>@yield("content")</body></html>');
        view()->getFinder()->prependLocation($dir);
        $request=\Illuminate\Http\Request::create('/admin/whatsapp/operations-center');
        $html=app(\Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\OperationsCenterController::class)->index($request)->render();
        $this->assertStringContainsString('Demo Shop Owner',$html);
        $this->assertStringContainsString('Preview help',$html);
        file_put_contents(base_path('.agents/operations-preview.html'),$html);
        $html=app(\Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\AiInboxController::class)->show($request,$conversation->id)->render();
        $this->assertStringContainsString('Demo Shop Owner',$html);
        $this->assertStringContainsString('Reply window open',$html);
        file_put_contents(base_path('.agents/inbox-preview.html'),$html);
    }

    #[Test]
    public function gateway_records_canonical_replies_once_and_diagnostics_stop_calling_them_silenced(): void
    {
        [$contact,,$conversation]=$this->application();$this->inbound($conversation);
        $this->assertTrue(app(ConciergeDiagnosticService::class)->diagnoseConversation($conversation)['is_silenced']);
        $response=$this->gateway()->sendTextMessage($contact->phone_number,'What is your shop name?');
        WhatsAppMessage::logOutbound($conversation->id,['type'=>'text','text'=>['body'=>'What is your shop name?']],$response);
        $this->assertSame(1,WhatsAppMessage::where('direction','outbound')->count());
        $this->assertSame('unresponsive_user',app(ConciergeDiagnosticService::class)->diagnoseConversation($conversation)['failure_category']);
    }
    #[Test]
    public function nudge_cooldown_applies_to_admins_and_provider_rejection_is_not_success(): void
    {
        [,,$conversation]=$this->application();$this->inbound($conversation);
        $this->gateway(400);$recovery=app(ConciergeRecoveryService::class);
        $this->assertSame('dry_run_passed',$recovery->renudgeCurrentStep($conversation,true)['status']);
        $this->assertSame('failed',$recovery->renudgeCurrentStep($conversation,false)['status']);
        $this->assertSame('excluded',$recovery->renudgeCurrentStep($conversation,false)['status']);
        $this->assertSame(0,ConciergeRecoveryAudit::where('status','success')->count());
        $this->assertSame(2,WhatsAppMessage::where('status','failed')->count());
    }
    #[Test]
    public function active_support_cases_are_never_released_or_nudged_by_recovery(): void
    {
        [$contact,,$conversation]=$this->application();$this->inbound($conversation);
        $case=app(SupportCaseService::class)->createCase($contact,'Please help','general','medium',null,$conversation);
        $conversation->updated_at=now()->subHours(4);$conversation->save();
        $r=app(ConciergeRecoveryService::class);
        $this->assertSame('excluded',$r->releaseStaleHandoff($conversation,false)['status']);
        $this->assertSame('excluded',$r->renudgeCurrentStep($conversation,false)['status']);
        $this->assertSame('open',$case->fresh()->status);
        $this->assertSame('human_handoff',$conversation->fresh()->state);
    }
    #[Test]
    public function legacy_replay_is_excluded_and_orphaned_handoff_can_be_released_without_data_loss(): void
    {
        [, $session,$conversation]=$this->application();$this->inbound($conversation);
        $r=app(ConciergeRecoveryService::class);
        $this->assertSame('excluded',$r->reprocessLastInbound($conversation,false)['status']);
        $conversation->state='human_handoff';$conversation->updated_at=now()->subHours(4);$conversation->save();
        $preview=$r->releaseStaleHandoff($conversation,true);
        $this->assertSame('dry_run_passed',$preview['status']);
        $this->assertSame('human_handoff',$conversation->fresh()->state);
        $this->assertSame('success',$r->releaseStaleHandoff($conversation,false)['status']);
        $this->assertSame('onboarding_active',$conversation->fresh()->state);
        $this->assertSame($session->collected_data,$session->fresh()->collected_data);
    }
    #[Test]
    public function contact_name_survives_messages_without_a_profile(): void
    {
        $contact=WhatsAppContact::findOrCreateByWhatsAppId('123','123',['profile'=>['name'=>'Aishat']]);
        WhatsAppContact::findOrCreateByWhatsAppId('123','123',[]);
        $this->assertSame('Aishat',$contact->fresh()->display_name);
        $this->assertNotNull($contact->fresh()->first_interaction_at);
    }
    #[Test]
    public function pending_review_does_not_hide_shop_vendors_when_dashboard_is_on_grocery(): void
    {
        foreach ([1=>'Grocery',3=>'Shop'] as $id=>$name) {
            DB::table('modules')->insert(['id'=>$id,'module_name'=>$name,'module_type'=>$id===1?'grocery':'ecommerce']);
            DB::table('vendors')->insert(['id'=>$id,'phone'=>'234000'.$id,'email'=>'vendor'.$id.'@example.test','status'=>null]);
            DB::table('stores')->insert(['id'=>$id,'name'=>$name.' applicant','phone'=>'234000'.$id,'vendor_id'=>$id,'module_id'=>$id]);
        }
        config(['module.current_module_id'=>1,'default_pagination'=>25]);
        $class=new \ReflectionClass(\App\Http\Controllers\Admin\VendorController::class);
        $controller=$class->newInstanceWithoutConstructor();$method=$class->getMethod('getNewStores');
        $all=$method->invoke($controller,\Illuminate\Http\Request::create('/admin/store/pending-requests'),null);
        $this->assertSame(2,$all->total());
        $shop=$method->invoke($controller,\Illuminate\Http\Request::create('/admin/store/pending-requests','GET',['module_id'=>3]),null);
        $this->assertSame([3],$shop->pluck('id')->all());
    }
}
