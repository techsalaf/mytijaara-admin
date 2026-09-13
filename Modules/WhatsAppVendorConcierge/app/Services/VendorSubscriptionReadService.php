<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use App\Models\Item;
use App\Models\Order;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Support\Facades\URL;

class VendorSubscriptionReadService
{
    /**
     * Get vendor subscription details and usage limits.
     *
     * @return array{
     *     business_model: string,
     *     is_subscription: bool,
     *     package_name: ?string,
     *     package_price: ?float,
     *     expiry_date: ?string,
     *     days_remaining: ?int,
     *     is_expired: bool,
     *     max_items: int,
     *     current_items: int,
     *     max_orders: int,
     *     current_orders: int,
     *     renewal_url: ?string,
     *     formatted_message: string
     * }
     */
    public function getSubscriptionSummary(Store $store): array
    {
        $businessModel = $store->store_business_model ?? 'commission';
        $isSubscription = in_array($businessModel, ['subscription', 'unsubscribed'], true);

        $subscription = $store->store_sub ?? null;
        $package = $subscription?->package ?? null;

        $packageName = $package?->package_name ?? null;
        $packagePrice = $package ? (float) $package->price : null;

        $expiryDate = $subscription?->expiry_date ? Carbon::parse($subscription->expiry_date) : null;
        $now = now();
        $daysRemaining = $expiryDate ? max(0, $now->diffInDays($expiryDate, false)) : null;
        $isExpired = $expiryDate ? $expiryDate->isPast() : false;

        $maxItems = (int) ($package?->max_item ?? 0);
        $currentItems = Item::withoutGlobalScopes()->where('store_id', $store->id)->count();

        $maxOrders = (int) ($package?->max_order ?? 0);
        $currentOrders = Order::withoutGlobalScopes()->where('store_id', $store->id)->count();

        // Generate safe canonical renewal link if route exists
        $renewalUrl = null;
        if (\Illuminate\Support\Facades\Route::has('restaurant.secondStep')) {
            $renewalUrl = URL::signedRoute('restaurant.secondStep', ['id' => $store->id], now()->addHours(24));
        } else {
            $renewalUrl = rtrim(config('app.url'), '/') . '/vendor-panel/subscription-renew';
        }

        $formatted = "📦 *MyTijaara Plan & Subscription Details*\n\n";
        $formatted .= "• *Business Model:* " . ucfirst($businessModel) . "\n";

        if ($isSubscription && $package) {
            $formatted .= "• *Active Package:* {$packageName} (₦" . number_format($packagePrice, 2) . ")\n";
            $formatted .= "• *Status:* " . ($isExpired ? '⚠️ Expired' : '✅ Active') . "\n";
            if ($expiryDate) {
                $formatted .= "• *Expires On:* {$expiryDate->toFormattedDateString()} ({$daysRemaining} days remaining)\n";
            }
            $itemLimitStr = $maxItems > 0 ? "{$currentItems} / {$maxItems}" : "{$currentItems} (Unlimited)";
            $orderLimitStr = $maxOrders > 0 ? "{$currentOrders} / {$maxOrders}" : "{$currentOrders} (Unlimited)";

            $formatted .= "\n*Usage Limits:*\n";
            $formatted .= "• Products: {$itemLimitStr}\n";
            $formatted .= "• Orders: {$orderLimitStr}\n";

            if ($isExpired || ($daysRemaining !== null && $daysRemaining <= 7)) {
                $formatted .= "\n⚠️ *Renewal Required:* Renew your package to maintain uninterrupted store operations.\n";
                $formatted .= "🔗 *Renewal Link:* {$renewalUrl}\n";
            }
        } elseif ($businessModel === 'commission') {
            $commission = $store->comission ?? config('default_commission', 10);
            $formatted .= "• *Plan Type:* Pay-as-you-go Commission\n";
            $formatted .= "• *Commission Rate:* {$commission}%\n";
            $formatted .= "• *Product & Order Limits:* Unlimited\n";
        } else {
            $formatted .= "• *Status:* No active package found.\n";
            $formatted .= "🔗 *Choose a Plan:* {$renewalUrl}\n";
        }

        return [
            'business_model' => $businessModel,
            'is_subscription' => $isSubscription,
            'package_name' => $packageName,
            'package_price' => $packagePrice,
            'expiry_date' => $expiryDate?->toDateString(),
            'days_remaining' => $daysRemaining,
            'is_expired' => $isExpired,
            'max_items' => $maxItems,
            'current_items' => $currentItems,
            'max_orders' => $maxOrders,
            'current_orders' => $currentOrders,
            'renewal_url' => $renewalUrl,
            'formatted_message' => $formatted,
        ];
    }
}
