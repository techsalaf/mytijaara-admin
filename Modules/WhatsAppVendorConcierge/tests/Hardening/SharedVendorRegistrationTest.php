<?php

namespace Modules\WhatsAppVendorConcierge\tests\Hardening;

use App\DTOs\VendorSelfRegistrationInput;
use App\Http\Controllers\VendorController;
use App\Models\BusinessSetting;
use App\Models\Store;
use App\Models\Translation;
use App\Models\Vendor;
use App\Services\VendorRegistrationNotifier;
use App\Services\VendorSelfRegistrationService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\WhatsAppVendorConcierge\app\DTOs\VendorApplicationDTO;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMedia;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Services\CoreAdapters\VendorApplicationService;
use Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService;
use Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway;
use PHPUnit\Framework\Attributes\DataProvider;

class SharedVendorRegistrationTest extends ApplicationFixtureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('zones')->insert(['id' => 1, 'name' => 'Fixture zone', 'status' => 1]);
        DB::table('modules')->insert(['id' => 1, 'module_name' => 'Fixture module', 'module_type' => 'grocery', 'status' => 1]);
        DB::table('module_zone')->insert(['module_id' => 1, 'zone_id' => 1]);
        session(['six_captcha' => 'fixture']);
        config(['toggle_store_registration_conf' => new BusinessSetting(['value' => '1']),
            'recaptcha_conf' => new BusinessSetting(['value' => '{"status":0}'])]);
    }

    private function input(array $overrides = []): VendorSelfRegistrationInput
    {
        return new VendorSelfRegistrationInput(...array_replace([
            'firstName' => 'Fixture', 'lastName' => 'Applicant', 'email' => 'fixture@example.test',
            'phone' => '+234 (800) 123-4567', 'names' => ['default' => 'Fixture Store', 'fr' => 'Magasin'],
            'addresses' => ['default' => 'Fixture Address', 'fr' => 'Adresse'],
            'latitude' => 5, 'longitude' => 5, 'zoneId' => 1, 'moduleId' => 1,
            'minimumDeliveryTime' => '20', 'maximumDeliveryTime' => '40', 'deliveryTimeUnit' => 'min',
            'businessPlan' => 'commission-base', 'packageId' => null,
            'logo' => UploadedFile::fake()->image('logo.png'), 'cover' => UploadedFile::fake()->image('cover.png'),
            'passwordHash' => bcrypt('Fixture-Only!123'), 'termsAccepted' => true, 'privacyAccepted' => true,
            'source' => 'web',
        ], $overrides));
    }

    public function test_public_controller_and_owned_concierge_media_share_the_same_graph(): void
    {
        $input = $this->input();
        $request = new Request([
            'f_name' => $input->firstName, 'l_name' => $input->lastName, 'email' => $input->email, 'phone' => $input->phone,
            'password' => 'Fixture-Only!123', 'lang' => ['default', 'fr'],
            'name' => array_values($input->names), 'address' => array_values($input->addresses),
            'latitude' => 5, 'longitude' => 5, 'zone_id' => 1, 'module_id' => 1,
            'minimum_delivery_time' => '20', 'maximum_delivery_time' => '40', 'delivery_time_type' => 'min',
            'business_plan' => 'commission-base', 'terms_accepted' => '1', 'privacy_accepted' => '1', 'custome_recaptcha' => 'fixture',
        ]);
        $request->files->set('logo', $input->logo);
        $request->files->set('cover_photo', $input->cover);
        $response = app(VendorController::class)->store($request);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayHasKey('redirect_url', $response->getData(true));
        $web = Store::firstOrFail();
        [$contact, $session, $conversation] = $this->draft();
        $session->update(['collected_data' => array_replace($session->collected_data, [
            'names' => $input->names, 'addresses' => $input->addresses,
            'logo_media_id' => $this->media($conversation, 'logo.png'),
            'cover_media_id' => $this->media($conversation, 'cover.png'),
        ])]);
        $wa = app(VendorApplicationService::class)->submit(VendorApplicationDTO::fromWhatsAppSession($session->fresh(), $contact));
        foreach (['name', 'address', 'latitude', 'longitude', 'zone_id', 'module_id', 'delivery_time', 'store_business_model', 'status', 'pickup_zone_id', 'tin_certificate_image'] as $field) {
            $this->assertSame($web->getRawOriginal($field), $wa['store']->fresh()->getRawOriginal($field), $field);
        }
        $this->assertNull($wa['vendor']->status);
        $this->assertSame('2348001234567', Vendor::first()->phone);
        $this->assertDatabaseHas('translations', ['translationable_id' => $wa['store']->id, 'locale' => 'fr', 'key' => 'name', 'value' => 'Magasin']);
        $this->assertDatabaseHas('translations', ['translationable_id' => $wa['store']->id, 'locale' => 'fr', 'key' => 'address', 'value' => 'Adresse']);
        $this->assertSame(4, Translation::count()); // Two translated values for each store.
    }

    public static function invalidFields(): array
    {
        return [
            'missing logo' => [['logo' => null], 'logo'],
            'missing cover' => [['cover' => null], 'cover_photo'],
            'missing surname' => [['lastName' => ''], 'l_name'],
            'invalid email' => [['email' => 'invalid'], 'email'],
            'latitude range' => [['latitude' => 91], 'latitude'],
            'longitude range' => [['longitude' => -181], 'longitude'],
            'missing latitude' => [['latitude' => null], 'latitude'],
            'inverted interval' => [['maximumDeliveryTime' => '10'], 'maximum_delivery_time'],
            'missing interval' => [['minimumDeliveryTime' => ''], 'minimum_delivery_time'],
            'unknown unit' => [['deliveryTimeUnit' => 'weeks'], 'delivery_time_type'],
            'terms missing' => [['termsAccepted' => false], 'terms_accepted'],
            'privacy missing' => [['privacyAccepted' => false], 'privacy_accepted'],
            'missing default name' => [['names' => ['fr' => 'Magasin']], 'name.default'],
            'invalid phone characters' => [['phone' => 'hello2348001234567'], 'phone'],
            'phone digits too short' => [['phone' => '+123'], 'phone'],
            'missing zone' => [['zoneId' => null], 'zone_id'],
            'unknown zone' => [['zoneId' => 99], 'zone_id'],
            'unknown module' => [['moduleId' => 99], 'module_id'],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_domain_values_never_create_an_application(array $overrides, string $field): void
    {
        try {
            app(VendorSelfRegistrationService::class)->register($this->input($overrides));
            $this->fail('Invalid application accepted');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey($field, $error->errors());
            $this->assertSame(0, Vendor::count());
            $this->assertSame(0, Store::count());
            $this->assertSame([], Storage::disk('public')->allFiles());
        }
    }

    public function test_custom_delivery_interval_is_preserved_instead_of_matching_a_preset_substring(): void
    {
        [$contact, $session] = $this->draft();
        $service = app(VendorOnboardingService::class);
        $extract = new \ReflectionMethod($service, 'extractStepData');
        $message = new WhatsAppMessage(['content' => ['text' => '120-240 min'], 'message_type' => 'text']);
        $values = $extract->invoke($service, $message, 'delivery_time', $contact);
        $this->assertSame('120-240 min', $values['delivery_time']);
        $session->update(['collected_data' => array_replace($session->collected_data, $values)]);
        $dto = VendorApplicationDTO::fromWhatsAppSession($session->fresh(), $contact);
        $this->assertSame('120', $dto->minimum_delivery_time);
        $this->assertSame('240', $dto->maximum_delivery_time);
    }

    public function test_optional_kyc_can_be_absent_or_supplied_as_a_valid_file(): void
    {
        $first = app(VendorSelfRegistrationService::class)->register($this->input());
        $this->assertSame('def.png', $first->store->tin_certificate_image);
        $second = app(VendorSelfRegistrationService::class)->register($this->input([
            'email' => 'second@example.test', 'phone' => '2348000000002',
            'tin' => 'Fixture TIN', 'tinCertificate' => UploadedFile::fake()->image('certificate.png'),
        ]));
        $this->assertSame('Fixture TIN', $second->store->tin);
        $this->assertNotSame('def.png', $second->store->tin_certificate_image);
        Storage::disk('public')->assertExists('store/'.$second->store->tin_certificate_image);
    }

    public function test_disguised_branding_and_optional_documents_are_rejected(): void
    {
        foreach (['logo', 'cover', 'tinCertificate'] as $field) {
            try {
                app(VendorSelfRegistrationService::class)->register($this->input([
                    $field => UploadedFile::fake()->createWithContent('disguised.png', '<?php echo "unsafe";'),
                ]));
                $this->fail('Disguised file accepted');
            } catch (ValidationException) {
                $this->assertSame(0, Vendor::count());
            }
        }
    }

    public function test_existing_formatted_phone_and_duplicate_email_are_rejected_consistently(): void
    {
        Vendor::create(['f_name' => 'Existing', 'l_name' => 'Applicant', 'email' => 'existing@example.test',
            'phone' => '+234 (800) 123-4567', 'password' => bcrypt('Fixture-Only!123')]);
        foreach ([['phone' => '2348001234567'], ['email' => 'existing@example.test', 'phone' => '2348000000002']] as $overrides) {
            try {
                app(VendorSelfRegistrationService::class)->register($this->input($overrides));
                $this->fail('Duplicate identity accepted');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey(isset($overrides['email']) ? 'email' : 'phone', $error->errors());
                $this->assertSame(1, Vendor::count());
                $this->assertSame(0, Store::count());
            }
        }
    }

    public function test_inactive_choices_and_invalid_module_zone_relationship_are_rejected(): void
    {
        foreach (['zone', 'module', 'relationship'] as $case) {
            DB::table('zones')->where('id', 1)->update(['status' => $case === 'zone' ? 0 : 1]);
            DB::table('modules')->where('id', 1)->update(['status' => $case === 'module' ? 0 : 1]);
            if ($case === 'relationship') {
                DB::table('module_zone')->delete();
            }
            try {
                app(VendorSelfRegistrationService::class)->register($this->input());
                $this->fail('Invalid choice accepted');
            } catch (ValidationException) {
                $this->assertSame(0, Vendor::count());
            }
        }
    }

    public function test_subscription_package_eligibility_and_unpaid_state_are_canonical(): void
    {
        Schema::create('subscription_packages', function ($table) {
            $table->id();
            $table->string('module_type');
            $table->boolean('status');
            $table->timestamps();
        });
        DB::table('business_settings')->where('key', 'subscription_business_model')->update(['value' => '1']);
        DB::table('subscription_packages')->insert([
            ['id' => 1, 'module_type' => 'all', 'status' => 0],
            ['id' => 2, 'module_type' => 'rental', 'status' => 1],
            ['id' => 3, 'module_type' => 'all', 'status' => 1],
        ]);
        foreach ([null, 1, 2, 99] as $package) {
            try {
                app(VendorSelfRegistrationService::class)->register($this->input(['businessPlan' => 'subscription-base', 'packageId' => $package]));
                $this->fail('Ineligible package accepted');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('package_id', $error->errors());
                $this->assertSame(0, Store::count());
            }
        }
        $result = app(VendorSelfRegistrationService::class)->register($this->input(['businessPlan' => 'subscription-base', 'packageId' => 3]));
        $this->assertTrue($result->subscriptionPaymentRequired);
        $this->assertSame('none', $result->store->store_business_model);
        $this->assertSame(3, $result->store->package_id);
        $this->assertSame(0, $result->store->status);
        $this->assertNull($result->vendor->status);
    }

    public function test_a_required_cover_cannot_be_skipped_in_chat(): void
    {
        $service = app(VendorOnboardingService::class);
        $method = new \ReflectionMethod($service, 'validateStep');
        $errors = $method->invoke($service, 'cover_branding', ['cover_media_id' => null, 'cover_skipped' => true]);
        $this->assertFalse($errors['valid']);
    }

    public function test_translation_failure_rolls_back_vendor_store_storage_metadata_and_media(): void
    {
        $notifier = \Mockery::mock(VendorRegistrationNotifier::class)->shouldNotReceive('send')->getMock();
        $this->app->instance(VendorRegistrationNotifier::class, $notifier);
        Translation::creating(static function () {
            throw new \RuntimeException('Injected translation failure');
        });
        try {
            app(VendorSelfRegistrationService::class)->register($this->input());
            $this->fail('Injected failure ignored');
        } catch (\RuntimeException $error) {
            $this->assertSame('Injected translation failure', $error->getMessage());
            $this->assertSame(0, Vendor::count());
            $this->assertSame(0, Store::count());
            $this->assertSame(0, DB::table('storages')->count());
            $this->assertSame([], Storage::disk('public')->allFiles());
        } finally {
            Translation::flushEventListeners();
        }
    }

    public function test_notifications_wait_for_the_outermost_commit_and_failure_does_not_undo_creation(): void
    {
        $calls = [];
        $notifier = \Mockery::mock(VendorRegistrationNotifier::class);
        $notifier->shouldReceive('send')->once()->andReturnUsing(function () use (&$calls) {
            $calls[] = DB::transactionLevel();
            throw new \RuntimeException('Injected delivery failure');
        });
        $this->app->instance(VendorRegistrationNotifier::class, $notifier);
        DB::beginTransaction();
        app(VendorSelfRegistrationService::class)->register($this->input());
        $this->assertSame([], $calls);
        DB::commit();
        $this->assertSame([0], $calls);
        $this->assertSame(1, Vendor::count());
        $this->assertSame(1, Store::count());
    }

    public function test_outer_rollback_removes_domain_graph_media_and_notifications(): void
    {
        $this->app->instance(VendorRegistrationNotifier::class, \Mockery::mock(VendorRegistrationNotifier::class)->shouldNotReceive('send')->getMock());
        DB::beginTransaction();
        app(VendorSelfRegistrationService::class)->register($this->input());
        DB::rollBack();
        $this->assertSame(0, Vendor::count());
        $this->assertSame(0, Translation::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_schedule_is_canonical_only_for_always_open_modules(): void
    {
        config(['module.grocery.always_open' => true]);
        $result = app(VendorSelfRegistrationService::class)->register($this->input());
        $this->assertSame(7, DB::table('store_schedule')->where('store_id', $result->store->id)->count());
        $this->assertDatabaseHas('store_schedule', ['store_id' => $result->store->id, 'day' => 0, 'opening_time' => '00:00:00', 'closing_time' => '23:59:59']);
    }

    public function test_dto_does_not_invent_missing_values_or_consent(): void
    {
        [$contact, $session] = $this->draft();
        $session->update(['collected_data' => []]);
        $dto = VendorApplicationDTO::fromWhatsAppSession($session->fresh(), $contact);
        $this->assertSame('', $dto->l_name);
        $this->assertSame('', $dto->f_name);
        $this->assertSame('', $dto->business_name);
        $this->assertSame('', $dto->phone);
        $this->assertNull($dto->latitude);
        $this->assertNull($dto->zone_id);
        $this->assertSame('', $dto->minimum_delivery_time);
        $this->assertSame('', $dto->business_plan);
        $this->assertFalse($dto->terms_accepted);
        $this->assertFalse($dto->privacy_accepted);
    }

    public function test_another_contacts_media_cannot_be_promoted(): void
    {
        [$contact, $session, $conversation] = $this->draft();
        $mediaId = $this->media($conversation, 'logo.png');
        $dto = VendorApplicationDTO::fromWhatsAppSession($session, $contact);
        $dto->logo = (string) $mediaId;
        $dto->metadata['contact_id'] = $contact->id + 100;
        $this->expectException(ValidationException::class);
        app(VendorApplicationService::class)->submit($dto);
    }

    public function test_legacy_missing_cover_can_be_reuploaded_and_submitted_without_public_draft_files(): void
    {
        Schema::create('subscription_packages', function ($table) {
            $table->id();
            $table->string('module_type');
            $table->boolean('status');
            $table->string('package_name');
            $table->decimal('price');
            $table->integer('validity');
            $table->timestamps();
        });
        [$contact, $session, $conversation] = $this->draft();
        $conversation->update(['current_step' => 'review_submit']);
        $session->updateData(['logo_media_id' => $this->media($conversation, 'logo.png')]);
        $texts = [];
        $gateway = \Mockery::mock(WhatsAppGateway::class);
        $gateway->shouldReceive('sendTextMessage')->andReturnUsing(function ($to, $body) use (&$texts) {
            $texts[] = $body;

            return [];
        });
        $gateway->shouldReceive('sendButtonMessage')->andReturn([]);
        $service = app(VendorOnboardingService::class);
        $service->submitApplication($conversation->fresh(), $contact, $session->fresh(), $gateway);
        $this->assertStringContainsString('edit cover', $texts[0]);
        $this->assertSame('started', $session->fresh()->status);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $service->processStep($conversation->fresh(), $contact, new WhatsAppMessage(['content' => ['text' => 'edit cover'], 'raw_text' => 'edit cover']), $gateway);
        $this->assertSame('cover_branding', $conversation->fresh()->current_step);
        $id = $this->media($conversation, 'cover.png');
        $message = WhatsAppMessage::where('media_id', $id)->firstOrFail();
        $service->processStep($conversation->fresh(), $contact, $message, $gateway);
        $this->assertSame('review_submit', $conversation->fresh()->current_step);
        $service->submitApplication($conversation->fresh(), $contact, $session->fresh(), $gateway);
        $this->assertSame('submitted', $session->fresh()->status);
        $this->assertSame(1, Vendor::count());
    }

    public function test_forging_session_metadata_does_not_grant_another_sessions_media(): void
    {
        [$contact, $session, $conversation] = $this->draft();
        $id = $this->media($conversation, 'logo.png');
        $dto = VendorApplicationDTO::fromWhatsAppSession($session, $contact);
        $dto->logo = $id;
        $dto->metadata['session_id'] = $session->id + 100;
        try {
            app(VendorApplicationService::class)->submit($dto);
            $this->fail('Forged ownership accepted');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('logo', $error->errors());
        }
        $this->assertSame(0, Vendor::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
        Storage::disk('local')->assertExists('staged/logo.png');
    }

    public function test_reusing_conversation_cannot_reassign_a_previous_sessions_media(): void
    {
        [$contact, $session, $conversation] = $this->draft();
        $id = $this->media($conversation, 'logo.png');
        $next = OnboardingSession::create(['contact_id' => $contact->id, 'status' => 'started',
            'collected_data' => $session->collected_data, 'expires_at' => now()->addDay()]);
        $conversation->update(['onboarding_session_id' => $next->id]);
        $dto = VendorApplicationDTO::fromWhatsAppSession($next, $contact);
        $dto->logo = $id;
        try {
            app(VendorApplicationService::class)->submit($dto);
            $this->fail('Old session media reassigned');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('logo', $error->errors());
        }
        $this->assertSame(0, Vendor::count());
    }

    public function test_inbound_snapshot_uses_server_conversation_not_caller_metadata_and_survives_replay(): void
    {
        [$contact, $session, $conversation] = $this->draft();
        $message = WhatsAppMessage::logInbound($conversation->id, ['id' => 'snapshot-fixture', 'type' => 'image',
            'from' => $contact->phone_number, 'image' => ['id' => 'media-fixture']],
            ['metadata' => ['registration_session_id' => 999, 'registration_contact_id' => 999]]);
        $this->assertSame($session->id, $message->metadata['registration_session_id']);
        $this->assertSame($contact->id, $message->metadata['registration_contact_id']);
        $conversation->update(['onboarding_session_id' => null]);
        $replayed = WhatsAppMessage::logInbound($conversation->id, ['id' => 'snapshot-fixture', 'type' => 'image'], []);
        $this->assertSame($session->id, $replayed->metadata['registration_session_id']);
        $this->assertSame($message->id, $replayed->id);
    }

    private function draft(): array
    {
        $contact = WhatsAppContact::create(['whatsapp_id' => '2348000000002', 'phone_number' => '2348000000002']);
        $session = OnboardingSession::create(['contact_id' => $contact->id, 'status' => 'started', 'current_step' => 'review_submit',
            'expires_at' => now()->addDay(), 'collected_data' => [
                'f_name' => 'Fixture', 'l_name' => 'Applicant', 'email' => 'wa@example.test', 'phone' => '2348000000002',
                'business_name' => 'Fixture Store', 'address' => 'Fixture Address', 'latitude' => 5, 'longitude' => 5,
                'zone_id' => 1, 'module_id' => 1, 'delivery_time' => '20-40 min', 'business_plan' => 'commission-base',
                'password_hash' => bcrypt('Fixture-Only!123'), 'terms_accepted' => true, 'privacy_accepted' => true,
            ]]);
        $conversation = WhatsAppConversation::create(['contact_id' => $contact->id, 'onboarding_session_id' => $session->id, 'state' => 'onboarding_active']);

        return [$contact, $session, $conversation];
    }

    private function media(WhatsAppConversation $conversation, string $name): string
    {
        $file = UploadedFile::fake()->image($name);
        Storage::disk('local')->put('staged/'.$name, file_get_contents($file->getPathname()));
        $media = WhatsAppMedia::create(['whatsapp_media_id' => $name, 'status' => 'processed', 'storage_disk' => 'local',
            'file_path' => 'staged/'.$name, 'mime_type' => 'image/png', 'expires_at' => now()->addDay()]);
        WhatsAppMessage::create(['conversation_id' => $conversation->id, 'whatsapp_message_id' => $name,
            'direction' => 'inbound', 'type' => 'image', 'media_id' => $media->id,
            'metadata' => ['registration_session_id' => $conversation->onboarding_session_id, 'registration_contact_id' => $conversation->contact_id]]);

        return (string) $media->id;
    }
}
