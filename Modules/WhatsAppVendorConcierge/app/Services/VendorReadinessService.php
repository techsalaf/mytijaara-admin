<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use App\Models\Store;

class VendorReadinessService
{
    /**
     * Calculate vendor launch readiness based on real core state.
     *
     * @return array{
     *     score: int,
     *     completed_items: array,
     *     remaining_items: array,
     *     blocking_items: array,
     *     next_action: string,
     *     formatted_message: string
     * }
     */
    public function calculateReadiness(Store $store): array
    {
        $vendor = $store->vendor ?? ($store->vendor_id ? \App\Models\Vendor::find($store->vendor_id) : null);

        $checks = [
            'application_approved' => [
                'title' => 'Application Approved',
                'description' => 'Vendor and store approved by MyTijaara administration',
                'weight' => 20,
                'is_blocking' => true,
                'completed' => (int) $store->status === 1 && (int) ($vendor?->status ?? 0) === 1,
                'action' => 'Await admin review and approval notification.',
            ],
            'subscription_active' => [
                'title' => 'Business Plan / Subscription Active',
                'description' => 'Active commission plan or valid subscription package',
                'weight' => 15,
                'is_blocking' => true,
                'completed' => $store->store_business_model === 'commission' || (bool) ($store->store_sub?->is_active ?? false),
                'action' => 'Select and activate a business plan or subscription package.',
            ],
            'store_profile' => [
                'title' => 'Store Profile Completeness',
                'description' => 'Store name, phone, zone, and verified address coordinates',
                'weight' => 15,
                'is_blocking' => true,
                'completed' => !empty($store->name) && !empty($store->phone) && !empty($store->address) && !empty($store->zone_id),
                'action' => 'Complete your store address, phone, and zone in your dashboard.',
            ],
            'branding_assets' => [
                'title' => 'Store Logo and Cover Photo',
                'description' => 'Branding assets uploaded for customer discovery',
                'weight' => 10,
                'is_blocking' => false,
                'completed' => !empty($store->logo) && $store->logo !== 'def.png' && !empty($store->cover_photo) && $store->cover_photo !== 'def.png',
                'action' => 'Upload a store logo and cover photo.',
            ],
            'operating_hours' => [
                'title' => 'Operating Hours Configured',
                'description' => 'Weekly store opening and closing schedules set',
                'weight' => 10,
                'is_blocking' => false,
                'completed' => method_exists($store, 'schedules') ? $store->schedules()->count() > 0 : false,
                'action' => 'Set weekly opening and closing hours for your shop.',
            ],
            'first_product' => [
                'title' => 'First Product Added',
                'description' => 'At least one item added to the store catalog',
                'weight' => 15,
                'is_blocking' => true,
                'completed' => method_exists($store, 'items') ? $store->items()->count() >= 1 : false,
                'action' => 'Add your first product by sending a product photo or details.',
            ],
            'product_available' => [
                'title' => 'Product Available for Ordering',
                'description' => 'At least one product active with available stock',
                'weight' => 10,
                'is_blocking' => false,
                'completed' => method_exists($store, 'items') ? $store->items()->where('status', 1)->where('stock', '>', 0)->count() >= 1 : false,
                'action' => 'Ensure products are activated and stock is greater than zero.',
            ],
            'payout_setup' => [
                'title' => 'Payout Details Configured',
                'description' => 'Bank account details on file for disbursement',
                'weight' => 5,
                'is_blocking' => false,
                'completed' => !empty($vendor?->account_no) || !empty($vendor?->bank_name),
                'action' => 'Configure your bank payout details in vendor settings.',
            ],
        ];

        $completedScore = 0;
        $completedItems = [];
        $remainingItems = [];
        $blockingItems = [];

        foreach ($checks as $key => $check) {
            if ($check['completed']) {
                $completedScore += $check['weight'];
                $completedItems[] = $check['title'];
            } else {
                $remainingItems[] = [
                    'key' => $key,
                    'title' => $check['title'],
                    'action' => $check['action'],
                    'is_blocking' => $check['is_blocking'],
                ];
                if ($check['is_blocking']) {
                    $blockingItems[] = $check['title'];
                }
            }
        }

        $nextAction = !empty($remainingItems) ? $remainingItems[0]['action'] : 'Your store is 100% ready for customer orders!';

        $formatted = "🚀 *Shop Launch Readiness: {$completedScore}%*\n\n";
        if ($completedScore === 100) {
            $formatted .= "🎉 *Congratulations!* Your shop is fully configured and ready to accept customer orders.\n";
        } else {
            $formatted .= "*Completed Items:* (" . count($completedItems) . "/" . count($checks) . ")\n";
            foreach ($completedItems as $item) {
                $formatted .= "  ✅ {$item}\n";
            }
            $formatted .= "\n*Pending Items:*\n";
            foreach ($remainingItems as $item) {
                $badge = $item['is_blocking'] ? '⚠️' : 'ℹ️';
                $formatted .= "  {$badge} *{$item['title']}*: {$item['action']}\n";
            }
            $formatted .= "\n👉 *Next Step:* {$nextAction}";
        }

        return [
            'score' => $completedScore,
            'completed_items' => $completedItems,
            'remaining_items' => $remainingItems,
            'blocking_items' => $blockingItems,
            'next_action' => $nextAction,
            'formatted_message' => $formatted,
        ];
    }
}
