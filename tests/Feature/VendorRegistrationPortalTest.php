<?php

namespace Tests\Feature;

use App\CentralLogics\Helpers;
use App\Services\System\DataSettingService;
use App\Services\System\FaqService;
use App\Services\System\SocialMediaService;
use App\Services\Customer\UserService;
use App\Services\System\ModuleService;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class VendorRegistrationPortalTest extends TestCase
{
    public function test_helpers_landing_meta_data_delegates_to_data_setting_service(): void
    {
        $mock = $this->createMock(DataSettingService::class);
        $mock->expects($this->once())
            ->method('getLandingMetaData')
            ->willReturn(['meta_title' => 'MyTijaara Marketplace']);

        $this->app->instance(DataSettingService::class, $mock);

        $result = Helpers::landing_meta_data();
        $this->assertSame(['meta_title' => 'MyTijaara Marketplace'], $result);
    }

    public function test_helpers_system_methods_delegate_to_underlying_services(): void
    {
        $faqMock = $this->createMock(FaqService::class);
        $faqMock->expects($this->once())
            ->method('getCachedByUserType')
            ->with('vendor', 5)
            ->willReturn(collect(['faq1']));
        $this->app->instance(FaqService::class, $faqMock);

        $socialMock = $this->createMock(SocialMediaService::class);
        $socialMock->expects($this->once())
            ->method('getActiveCached')
            ->willReturn(collect(['twitter']));
        $this->app->instance(SocialMediaService::class, $socialMock);

        $userMock = $this->createMock(UserService::class);
        $userMock->expects($this->once())
            ->method('getBasicByIds')
            ->with([1, 2])
            ->willReturn(collect());
        $this->app->instance(UserService::class, $userMock);

        $this->assertCount(1, Helpers::faqs_by_user_type('vendor', 5));
        $this->assertCount(1, Helpers::social_media_active());
        $this->assertCount(0, Helpers::customers_by_ids([1, 2]));
    }

    public function test_whatsapp_concierge_module_is_enabled_in_modules_statuses(): void
    {
        $statuses = json_decode(file_get_contents(base_path('modules_statuses.json')), true);
        $this->assertIsArray($statuses);
        $this->assertTrue(isset($statuses['WhatsAppVendorConcierge']) && $statuses['WhatsAppVendorConcierge'] === true);
    }

    public function test_whatsapp_webhook_route_is_registered(): void
    {
        $this->assertTrue(Route::has('whatsapp.webhook.verify'));
        $this->assertTrue(Route::has('whatsapp.webhook.handle'));
        $this->assertTrue(Route::has('whatsapp.webhook.health'));
    }
}
