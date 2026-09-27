<?php

namespace Modules\WhatsAppVendorConcierge\tests\Hardening;

use App\Models\Category;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderDefinition;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderModel;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMedia;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Services\LaunchTaxonomyService;
use Modules\WhatsAppVendorConcierge\app\Services\ProductFieldMap;
use Modules\WhatsAppVendorConcierge\app\Services\ProductListingFlow;
use Modules\WhatsAppVendorConcierge\app\Services\ProductVisionService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ProductListingFlowTest extends OperationsFixtureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Schema::create('units', fn (Blueprint $t) => [$t->id(), $t->string('unit')]);
        Schema::create('system_tax_setups', fn (Blueprint $t) => [$t->id(), $t->boolean('is_active'), $t->boolean('is_default'), $t->string('tax_payer')->default('vendor'), $t->boolean('is_included')->default(0), $t->string('tax_type')]);
        Schema::create('attributes', fn (Blueprint $t) => [$t->id(), $t->string('name')]);
        Schema::table('items', fn (Blueprint $t) => [$t->text('food_variations')->nullable(), $t->unsignedBigInteger('unit_id')->nullable(), $t->text('images')->nullable()]);
        Schema::table('categories', fn (Blueprint $t) => $t->integer('priority')->default(0));
    }

    private function say(string $text): string
    {
        return app(ProductListingFlow::class)->receive($this->conversation, $this->contact, new WhatsAppMessage(['type' => 'text', 'raw_text' => $text, 'content' => ['text' => $text]]));
    }

    private function draft()
    {
        return app(ProductListingFlow::class)->current($this->conversation);
    }

    public function test_cancel_confirmation_buttons_expire_when_editing_resumes(): void
    {
        $this->say('add product');
        $this->say('cancel');
        $draft = $this->draft();
        $id = \Modules\WhatsAppVendorConcierge\app\Services\ProductListingPresenter::id($draft, 'confirm cancel');
        $gateway = \Mockery::mock(\Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway::class);
        $gateway->shouldReceive('sendButtonMessage')->once()->withArgs(function ($phone, $body, $buttons) {
            return count($buttons) === 3 && str_contains($buttons[0]['id'], 'confirm%20cancel');
        })->andReturn([]);
        app(\Modules\WhatsAppVendorConcierge\app\Services\ProductListingPresenter::class)->send($gateway, $this->contact->phone_number, 'Confirm cancellation?', $draft);
        $this->say('keep editing');
        $this->assertNull(\Modules\WhatsAppVendorConcierge\app\Services\ProductListingPresenter::decode($this->draft(), $id));
        $this->say('confirm cancel');
        $this->assertSame('active', $this->draft()->status);
        $this->say('cancel');
        $this->say('save draft');
        $this->assertSame('saved', $this->draft()->status);
        $this->assertArrayNotHasKey('_cancel_requested', $this->draft()->sources);
    }

    public function test_product_review_command_bypasses_ai_and_scopes_the_dashboard_card(): void
    {
        $this->say('add product');
        $service = \Mockery::mock(\Modules\WhatsAppVendorConcierge\app\Services\ProductModerationStatusService::class);
        $service->shouldReceive('products')->once()->with($this->store->id, null)->andReturn([
            ['id'=>23,'name'=>'Bag','status'=>'Needs correction'],
        ]);
        $this->app->instance(\Modules\WhatsAppVendorConcierge\app\Services\ProductModerationStatusService::class, $service);
        $gateway = \Mockery::mock(\Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway::class);
        $gateway->shouldReceive('sendListMessage')->once()->withArgs(fn ($phone, $body, $sections) => $sections[0]['rows'][0]['id'] === 'product_status:23')->andReturn([]);
        $ai = \Mockery::mock(\Modules\WhatsAppVendorConcierge\app\Services\AiFallbackService::class);
        $ai->shouldNotReceive('promptAgent');
        $router = new \Modules\WhatsAppVendorConcierge\app\Services\ConversationOrchestrator(app(\Modules\WhatsAppVendorConcierge\app\Services\ConversationManager::class), $ai);
        $this->assertTrue($router->routeMessage($this->conversation, $this->contact, new WhatsAppMessage(['type'=>'text','raw_text'=>'product status']), $gateway));
        $this->assertNotNull($this->draft());
        $this->assertNotNull(app('router')->getRoutes()->getByName('vendor.item.edit'));
    }

    public function test_sensitive_onboarding_steps_never_reach_ai_extraction(): void
    {
        $ai = \Mockery::mock(\Modules\WhatsAppVendorConcierge\app\Services\AiFallbackService::class);
        $ai->shouldNotReceive('promptAgent');
        $router = new \Modules\WhatsAppVendorConcierge\app\Services\ConversationOrchestrator(app(\Modules\WhatsAppVendorConcierge\app\Services\ConversationManager::class), $ai);
        $gateway = \Mockery::mock(\Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway::class);
        foreach (['account_password', 'kyc_documents'] as $step) {
            $this->assertFalse($router->handleOnboardingExtraction($this->conversation, $this->contact, new WhatsAppMessage(['raw_text'=>'private fixture']), $gateway, $step, []));
        }
    }

    public function test_review_is_grouped_and_uses_human_labels_and_naira(): void
    {
        $flow=app(ProductListingFlow::class);$d=$flow->start($this->conversation,$this->contact);
        $d->update(['step'=>'review','data'=>['name'=>'Earphones','description'=>'Wireless audio','price'=>13000,'stock'=>20,'discount'=>10,'category_id'=>$this->category->id,'media_id'=>29,'additional_media'=>[30,31]]]);
        $reply=$flow->prompt($d);
        foreach(['🛍️ *Review your product*','📦 *Product details*','💰 *Price & stock*','• *Price:* ₦13,000.00',"\n\n📝 *Description*",'📷 *Photos*'] as $text)$this->assertStringContainsString($text,$reply);
        $this->assertStringNotContainsString('Media id',$reply);$this->assertStringNotContainsString('[vendor]',$reply);
    }

    public function test_manage_shop_uses_configured_login_path_in_clickable_button(): void
    {
        if(!Schema::hasTable('data_settings'))Schema::create('data_settings',fn(Blueprint $t)=>[$t->id(),$t->string('key'),$t->string('value')]);
        DB::table('data_settings')->insert(['key'=>'store_login_url','value'=>'merchant-access']);
        $gateway=\Mockery::mock(\Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway::class);
        $gateway->shouldReceive('sendCtaUrlMessage')->once()->withArgs(function($phone,$body,$label,$url){return $label==='Open dashboard' && $url===url('/login/merchant-access') && str_contains($body,'🏪 *Manage') && !str_contains($body,'seller.mytijaara');})->andReturn([]);
        app(\Modules\WhatsAppVendorConcierge\app\Services\ConversationManager::class)->showManageShop($this->conversation,$this->contact,$gateway);
    }

    public function test_extra_photos_acknowledge_progress_done_button_and_fifth_photo_advances(): void
    {
        $media=(int)$this->productData([])['image'];
        $flow=app(ProductListingFlow::class);$d=$flow->start($this->conversation,$this->contact);
        $data=['image'=>$media,'media_id'=>$media,'name'=>'Earphones','description'=>'Wireless earphones','category_id'=>$this->category->id,'unit_id'=>null,'price'=>13000,'discount'=>0,'stock'=>30,'attribute_ids'=>[],'extra_details'=>0];
        $d->update(['data'=>$data,'step'=>'additional_media']);
        $reply=$flow->receive($this->conversation,$this->contact,new WhatsAppMessage(['type'=>'image','media_id'=>$media]));
        $this->assertStringContainsString('1 of 5',$reply);
        $this->assertStringContainsString('Done with photos',$reply);
        $this->say('done');$this->assertSame('review',$d->fresh()->step);
        $photos=[$media];
        for($i=0;$i<4;$i++){
            $copy=\Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMedia::find($media)->replicate();$copy->whatsapp_media_id='extra-'.$i;$copy->save();
            WhatsAppMessage::create(['conversation_id'=>$this->conversation->id,'media_id'=>$copy->id,'direction'=>'inbound','type'=>'image','content'=>[],'whatsapp_message_id'=>'extra-'.$i]);$photos[]=$copy->id;
        }
        $d->refresh()->update(['step'=>'additional_media','data'=>$data+['additional_media'=>array_slice($photos,0,4)]]);
        $flow->receive($this->conversation,$this->contact,new WhatsAppMessage(['type'=>'image','media_id'=>$photos[4]]));
        $this->assertSame('review',$d->fresh()->step);
        $flow->receive($this->conversation,$this->contact,new WhatsAppMessage(['type'=>'image','media_id'=>$photos[4]]));
        $this->assertSame($media,$d->fresh()->data['media_id']);
        $this->assertCount(5,$d->fresh()->data['additional_media']);
    }

    public function test_controls_are_scoped_to_question_and_paginate_without_losing_choices(): void
    {
        $flow=app(ProductListingFlow::class);$d=$flow->start($this->conversation,$this->contact);
        $d->update(['step'=>'extra_details']);
        $presenter=app(\Modules\WhatsAppVendorConcierge\app\Services\ProductListingPresenter::class);
        $gateway=\Mockery::mock(\Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway::class);
        $gateway->shouldReceive('sendButtonMessage')->once()->withArgs(function($phone,$body,$buttons){return count($buttons)===2 && $buttons[0]['title']==='Yes';})->andReturn([]);
        $presenter->send($gateway,'123',$flow->prompt($d),$d);
        $id=$presenter::id($d,'yes');$d->update(['step'=>'price']);
        $reply=$flow->receive($this->conversation,$this->contact,new WhatsAppMessage(['type'=>'interactive_button','content'=>['interactive'=>['button_reply'=>['id'=>$id]]]]));
        $this->assertStringContainsString('earlier step',$reply);$this->assertSame('price',$d->fresh()->step);
        for($i=0;$i<14;$i++)DB::table('units')->insert(['unit'=>'Unit '.$i]);
        $d->update(['step'=>'unit_id']);$d->refresh();
        $gateway->shouldReceive('sendListMessage')->once()->withArgs(function($phone,$body,$sections){return count($sections[0]['rows'])===9 && $sections[0]['rows'][8]['title']==='More options →';})->andReturn([]);
        $presenter->send($gateway,'123','Choose a unit',$d);
        $gateway->shouldReceive('sendListMessage')->once()->withArgs(function($phone,$body,$sections){return count($sections[0]['rows'])===8 && $sections[0]['rows'][6]['title']==='Skip this step';})->andReturn([]);
        $presenter->send($gateway,'123','Choose a unit',$d,1);
    }

    public function test_product_button_is_routed_before_generic_onboarding_buttons(): void
    {
        $flow=app(ProductListingFlow::class);$d=$flow->start($this->conversation,$this->contact);
        $d->update(['step'=>'extra_details']);
        $id=\Modules\WhatsAppVendorConcierge\app\Services\ProductListingPresenter::id($d,'yes');
        $message=new WhatsAppMessage(['type'=>'interactive_button','content'=>['interactive'=>['button_reply'=>['id'=>$id]]]]);
        $gateway=\Mockery::mock(\Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway::class);
        $gateway->shouldReceive('sendButtonMessage')->once()->andReturn([]);
        $job=new \Modules\WhatsAppVendorConcierge\app\Jobs\ProcessIncomingWhatsAppMessage([],[]);
        (new \ReflectionMethod($job,'processByState'))->invoke($job,$this->conversation,$this->contact,$message,app(\Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService::class),app(\Modules\WhatsAppVendorConcierge\app\Services\ConversationManager::class),$gateway);
        $this->assertSame(1,$d->fresh()->data['extra_details']);
    }

    public function test_empty_album_events_do_not_prompt_or_change_progress(): void
    {
        $flow=app(ProductListingFlow::class);$d=$flow->start($this->conversation,$this->contact);
        $gateway=\Mockery::mock(\Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway::class);
        $job=new \Modules\WhatsAppVendorConcierge\app\Jobs\ProcessIncomingWhatsAppMessage(['id'=>'empty-event','from'=>'123'],[]);
        $job->handle($gateway,app(\Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService::class),app(\Modules\WhatsAppVendorConcierge\app\Services\ConversationManager::class));
        $this->assertSame('image',$d->fresh()->step);$this->assertSame(0,$d->fresh()->stalled_turns);
    }

    public function test_exact_production_failure_recovers_valid_details_and_only_asks_missing_category(): void
    {
        $media = (int) $this->productData([])['image'];
        $this->conversation->update(['context' => ['last_product_media_id' => $media, 'photo_to_product_draft' => ['name' => 'Thinkplus Ear Pod 3', 'description' => 'Black Lenovo earphones', 'price' => 13000, 'stock' => 30, 'media_id' => $media, 'category_id' => null]]]);
        $d = app(ProductListingFlow::class)->start($this->conversation, $this->contact);
        $this->assertSame('category_id', $d->step);
        $reply = $this->say('Watches');
        $this->assertStringContainsString('No exact matching option', $reply);
        $this->assertStringNotContainsString('provide Name', $reply);
        $this->assertSame(13000, $this->draft()->data['price']);
        $this->say((string) $this->category->id);
        $this->assertSame('unit_id', $this->draft()->step);
        $this->say('skip');
        $this->say('skip');
        $this->say('skip');
        $this->say('skip');
        $this->say('skip');
        $this->assertSame('review', $this->draft()->step);
        $reply = $this->say('confirm and create');
        $this->assertStringContainsString('Product #', $reply);
        $this->assertSame(1, DB::table('items')->count());
        $this->assertStringContainsString('Already created', $this->say('confirm'));
        $this->assertSame(1, DB::table('items')->count());
    }

    public function test_back_save_resume_invalid_answer_and_loop_flag(): void
    {
        $this->say('add product');
        $this->say('skip');
        $this->say('skip');
        $this->say('skip');
        $this->assertTrue($this->draft()->needs_attention);
        $this->say('save draft');
        $this->assertSame('saved', $this->draft()->status);
        $this->say('resume product');
        $this->assertSame('active', $this->draft()->status);
        $this->say('edit name');
        $this->say('Blue Bag');
        $this->assertSame('Blue Bag', $this->draft()->data['name']);
        $this->say('cancel');
        $this->assertSame('active', $this->draft()->status);
        $this->say('keep editing');
        $this->assertSame('Blue Bag', $this->draft()->data['name']);
        $this->say('cancel');
        $this->say('confirm cancel');
        $this->assertNull($this->draft());
        $this->assertSame(0, DB::table('items')->count());
    }

    public function test_image_fallback_and_multiple_facts_are_preserved(): void
    {
        $media = (int) $this->productData([])['image'];
        $flow = app(ProductListingFlow::class);
        $reply = $flow->receive($this->conversation, $this->contact, new WhatsAppMessage(['type' => 'image', 'media_id' => $media]));
        $this->assertStringContainsString('step by step', $reply);
        $this->assertSame('name', $this->draft()->step);
        $this->say('This is Golden Penny Semovita, 1kg, and I sell it for ₦1,800.');
        $this->assertSame(1800, $this->draft()->data['price']);
        $this->assertSame('category_id', $this->draft()->step);
    }

    public function test_module_fields_follow_host_config(): void
    {
        $map = app(ProductFieldMap::class);
        foreach (['grocery', 'ecommerce', 'pharmacy', 'food', 'parcel', 'rental'] as $type) {
            $this->module->update(['module_type' => $type]);
            $steps = $map->steps($this->store->fresh());
            if (in_array($type, ['parcel', 'rental'])) {
                $this->assertSame([], $steps);

                continue;
            }$this->assertSame($type !== 'food', in_array('stock', $steps));
            $this->assertSame($type === 'food', in_array('veg', $steps));
            $this->assertSame($type === 'pharmacy', in_array('is_prescription_required', $steps));
        }
    }

    public function test_taxonomy_preview_apply_repeat_preserve_and_parent_relationships(): void
    {
        $service = app(LaunchTaxonomyService::class);
        $before = DB::table('categories')->count();
        $preview = $service->run();
        $this->assertGreaterThan(10, $preview['counts']['created']);
        $this->assertSame($before, DB::table('categories')->count());
        $first = $service->run(true);
        $count = DB::table('categories')->count();
        $second = $service->run(true);
        $this->assertSame($count, DB::table('categories')->count());
        $this->assertArrayNotHasKey('created', $second['counts']);
        $this->assertSame($this->category->id, DB::table('categories')->where('name', $this->category->name)->value('id'));
        $child = DB::table('categories')->where('name', 'Rice')->first();
        $this->assertSame($this->category->id, $child->parent_id);
        $this->assertSame('def.png', $child->image);
        $this->assertNotNull($first['backup']);
    }

    public function test_vision_attaches_bytes_and_rejects_invented_categories(): void
    {
        $media = WhatsAppMedia::find((int) $this->productData([])['image']);
        $definition = AiProviderDefinition::create(['slug' => 'nvidia_nim', 'name' => 'NVIDIA', 'adapter_class' => 'NvidiaNimAdapter', 'default_base_url' => ProductVisionService::ENDPOINT]);
        $connection = AiProviderConnection::create(['definition_id' => $definition->id, 'name' => 'Vision', 'credentials' => ['api_key' => 'fixture-key'], 'is_active' => true]);
        AiProviderModel::create(['connection_id' => $connection->id, 'model_id' => ProductVisionService::MODEL, 'name' => 'Vision', 'is_enabled' => true]);
        Http::fake(['*/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode(['name' => 'Bag', 'description' => 'A blue bag', 'category_id' => 99999, 'confidence' => 0.9])]]]])]);
        $result = app(ProductVisionService::class)->analyse($media, $this->store);
        $this->assertSame('noncanonical_category', $result['error']);
        Http::assertSent(fn ($r) => str_starts_with($r['messages'][0]['content'][1]['image_url']['url'], 'data:image/png;base64,') && ! isset($r['price']));
    }

    public function test_real_message_id_is_consumed_once_and_unowned_draft_is_rejected(): void
    {
        $this->say('add product');
        $this->say('edit name');
        $message = WhatsAppMessage::create(['conversation_id' => $this->conversation->id, 'whatsapp_message_id' => 'unique-retry', 'direction' => 'inbound', 'type' => 'text', 'raw_text' => 'Blue Bag', 'content' => ['text' => 'Blue Bag']]);
        $flow = app(ProductListingFlow::class);
        $flow->receive($this->conversation, $this->contact, $message);
        $before = $this->draft()->data;
        $this->assertSame('', $flow->receive($this->conversation, $this->contact, $message));
        $this->assertSame($before, $this->draft()->data);
        $other = clone $this->contact;
        $other->id = 999;
        try {
            $flow->receive($this->conversation, $other, $message);
            $this->fail('Unowned draft accepted');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_taxonomy_conflict_rolls_back_all_new_records(): void
    {
        foreach ([1, 2] as $i) {
            Category::forceCreate(['name' => 'Fresh Food', 'module_id' => $this->module->id, 'parent_id' => 0, 'position' => 0, 'status' => 1]);
        }
        $count = DB::table('categories')->count();
        try {
            app(LaunchTaxonomyService::class)->run(true);
            $this->fail('Conflict was applied');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('conflict', $e->getMessage());
        }
        $this->assertSame($count, DB::table('categories')->count());
        $this->assertSame(0, DB::table('whatsapp_taxonomy_keys')->count());
    }

    public function test_variants_require_each_price_and_stock_and_preserve_host_shape(): void
    {
        DB::table('attributes')->insert(['id' => 1, 'name' => 'Colour']);
        $flow = app(ProductListingFlow::class);
        $d = $flow->start($this->conversation, $this->contact);
        foreach (['attribute_ids' => '1', 'choice_1' => 'Black, Blue', 'variant_price_0' => '1000', 'variant_stock_0' => '2', 'variant_price_1' => '1100', 'variant_stock_1' => '3'] as $field => $value) {
            $flow->answer($d, $field, $value);
        }
        $this->assertSame(['Black', 'Blue'], app(ProductFieldMap::class)->combinations($d->data));
        $d->data = array_merge($d->data, $this->productData(['name' => 'Bag', 'price' => 1000, 'category_id' => $this->category->id]));
        $d->data = array_merge($d->data, ['media_id' => (int) $d->data['image']]);
        $d->step = 'review';
        $d->save();
        $flow->create($d, $this->contact);
        $item = DB::table('items')->first();
        $this->assertSame(5, $item->stock);
        $this->assertSame([['type' => 'Black', 'price' => 1000, 'stock' => 2], ['type' => 'Blue', 'price' => 1100, 'stock' => 3]], json_decode($item->variations, true));
    }

    public function test_food_options_reject_impossible_limits_before_creating(): void
    {
        $this->module->update(['module_type' => 'food']);
        $flow = app(ProductListingFlow::class);
        $d = $flow->start($this->conversation, $this->contact);
        $d->data = ['name' => 'Rice', 'description' => 'Rice meal', 'category_id' => $this->category->id, 'price' => 1000, 'food_group_count' => 1, 'food_0_name' => 'Protein', 'food_0_required' => 'on', 'food_0_min' => 3, 'food_0_max' => 1, 'food_0_options' => ['Egg'], 'food_0_price_0' => 100];
        $d->step = 'review';
        $d->save();
        try {
            $flow->create($d,$this->contact);
            $this->fail('Invalid food group created');
        } catch (ValidationException) {
            $this->assertSame(0,DB::table('items')->count());
        }
    }
    public function test_busy_draft_queues_the_stored_reply_independently_of_webhook_deduplication(): void
    {
        $flow=app(ProductListingFlow::class);$draft=$flow->start($this->conversation,$this->contact);
        $message=WhatsAppMessage::create(['conversation_id'=>$this->conversation->id,'whatsapp_message_id'=>'busy-reply','direction'=>'inbound','type'=>'text','raw_text'=>'Blue Bag','content'=>['text'=>'Blue Bag']]);
        $busy=\Mockery::mock(ProductListingFlow::class,[app(ProductFieldMap::class)])->makePartial();
        $busy->shouldReceive('receive')->once()->andThrow(new \Illuminate\Contracts\Cache\LockTimeoutException());
        $this->assertStringContainsString('reply is saved',$busy->receiveOrDefer($this->conversation,$this->contact,$message));
        \Illuminate\Support\Facades\Queue::assertPushed(\Modules\WhatsAppVendorConcierge\app\Jobs\ResumeProductDraftMessage::class,fn($job)=>$job->draftId===$draft->id && $job->messageId===$message->id);
    }

}
