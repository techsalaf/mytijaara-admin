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

        $subscription = $store->store_sub;
        $planActive = $store->store_business_model === 'commission' || ($subscription
            && (int) $subscription->status === 1
            && ($subscription->max_order === 'unlimited' || (int) $subscription->max_order > 0)
            && $subscription->expiry_date && \Carbon\Carbon::parse($subscription->expiry_date)->endOfDay()->isFuture());
        $coordinates = is_numeric($store->latitude) && is_numeric($store->longitude)
            && abs((float)$store->latitude) <= 90 && abs((float)$store->longitude) <= 180;
        // Reuse the customer's canonical approval/module/category/plan scope.
        $available = $store->items()->active(null, $store->module_id);
        if (config('module.'.$store->module?->module_type.'.stock', true)) $available->where('stock', '>', 0);
        $productAvailable = $available->exists();
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
                'completed' => $planActive,
                'action' => 'Select and activate a business plan or subscription package.',
            ],
            'store_profile' => [
                'title' => 'Store Profile Completeness',
                'description' => 'Store name, phone, zone, and saved pickup/fulfilment coordinates',
                'weight' => 15,
                'is_blocking' => true,
                'completed' => !empty($store->name) && !empty($store->phone) && !empty($store->address) && !empty($store->zone_id) && $coordinates,
                'action' => 'Complete your store address, phone, and zone in your dashboard.',
            ],
            'branding_assets' => [
                'title' => 'Store Logo and Cover Photo',
                'description' => 'Branding assets uploaded for customer discovery',
                'weight' => 5,
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
                'weight' => 10,
                'is_blocking' => true,
                'completed' => method_exists($store, 'items') ? $store->items()->count() >= 1 : false,
                'action' => 'Add your first product by sending a product photo or details.',
            ],
            'product_available' => [
                'title' => 'Product Available for Ordering',
                'description' => 'An approved product passes the customer visibility rules and applicable stock checks',
                'weight' => 10,
                'is_blocking' => true,
                'completed' => $productAvailable,
                'action' => 'Check product approval, active category/module, and stock where applicable.',
            ],
            'store_open' => [
                'title' => 'Shop Open', 'description' => 'Merchant availability is enabled',
                'weight' => 5, 'is_blocking' => true, 'completed' => (bool) $store->active,
                'action' => 'Open your shop when you are ready to accept orders.',
            ],
            'fulfilment' => [
                'title' => 'Delivery or Pickup Enabled', 'description' => 'At least one fulfilment option is configured',
                'weight' => 5, 'is_blocking' => true, 'completed' => (bool) $store->delivery || (bool) $store->take_away,
                'action' => 'Enable pickup or configure delivery. Store-managed delivery means your shop arranges its own delivery; platform riders are not automatically assigned.',
            ],
            'payout_setup' => [
                'title' => 'Payout Details Configured',
                'description' => 'Bank account details on file for disbursement',
                'weight' => 5,
                'is_blocking' => false,
                'completed' => !empty($vendor?->account_no) && !empty($vendor?->bank_name),
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

        $priorityItem = collect($remainingItems)->firstWhere('is_blocking', true) ?? ($remainingItems[0] ?? null);
        $nextAction = $priorityItem ? $priorityItem['action'] : 'Your setup checklist is complete. Live opening hours, delivery availability and checkout checks still apply.';

        $formatted = "🚀 *Shop Launch Readiness: {$completedScore}%*\n\n";
        if ($completedScore === 100) {
            $formatted .= "🎉 *Congratulations!* Your setup checklist is complete. Customer ordering still depends on live hours, fulfilment availability and checkout checks.\n";
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
