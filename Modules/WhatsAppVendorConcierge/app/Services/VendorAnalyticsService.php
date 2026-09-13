<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use App\Models\Item;
use App\Models\Order;
use App\Models\Review;
use App\Models\Store;

class VendorAnalyticsService
{
    /**
     * Compute comprehensive store analytics and business insights using canonical accounting.
     *
     * @return array{
     *     sales_summary: array,
     *     order_summary: array,
     *     rating_summary: array,
     *     top_products: array,
     *     low_stock_items: array,
     *     formatted_message: string
     * }
     */
    public function getBusinessInsights(Store $store): array
    {
        $storeId = $store->id;

        // Canonical Sales: Only delivered orders are recognized revenue
        $todayDelivered = Order::withoutGlobalScopes()
            ->where('store_id', $storeId)
            ->where('order_status', 'delivered')
            ->whereDate('created_at', now())
            ->sum('order_amount');

        $thisWeekDelivered = Order::withoutGlobalScopes()
            ->where('store_id', $storeId)
            ->where('order_status', 'delivered')
            ->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->sum('order_amount');

        $thisMonthDelivered = Order::withoutGlobalScopes()
            ->where('store_id', $storeId)
            ->where('order_status', 'delivered')
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('order_amount');

        $salesSummary = [
            'today' => (float) $todayDelivered,
            'this_week' => (float) $thisWeekDelivered,
            'this_month' => (float) $thisMonthDelivered,
        ];

        // Order Counts
        $todayOrders = Order::withoutGlobalScopes()->where('store_id', $storeId)->whereDate('created_at', now())->count();
        $pendingOrders = Order::withoutGlobalScopes()->where('store_id', $storeId)->where('order_status', 'pending')->count();
        $deliveredOrders = Order::withoutGlobalScopes()->where('store_id', $storeId)->where('order_status', 'delivered')->count();
        $canceledOrders = Order::withoutGlobalScopes()->where('store_id', $storeId)->where('order_status', 'canceled')->count();

        $orderSummary = [
            'today' => $todayOrders,
            'pending' => $pendingOrders,
            'delivered' => $deliveredOrders,
            'canceled' => $canceledOrders,
        ];

        // Rating & Reviews
        $avgRating = 0.0;
        $totalReviews = 0;
        $recentReviews = [];

        if (class_exists(Review::class)) {
            $avgRating = (float) (Review::where('store_id', $storeId)->avg('rating') ?? 0.0);
            $totalReviews = Review::where('store_id', $storeId)->count();
            $recentReviews = Review::where('store_id', $storeId)
                ->orderBy('id', 'desc')
                ->limit(3)
                ->get(['id', 'rating', 'comment', 'created_at'])
                ->map(fn ($r) => [
                    'rating' => (int) $r->rating,
                    'comment' => $r->comment ? substr($r->comment, 0, 100) : null,
                    'date' => $r->created_at?->toDateString(),
                ])
                ->toArray();
        }

        $ratingSummary = [
            'average' => round($avgRating, 1),
            'total' => $totalReviews,
            'recent' => $recentReviews,
        ];

        // Top Selling Products
        $topProducts = Item::withoutGlobalScopes()
            ->where('store_id', $storeId)
            ->where('status', 1)
            ->orderBy('order_count', 'desc')
            ->limit(5)
            ->get(['id', 'name', 'price', 'order_count'])
            ->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'price' => (float) $item->price,
                'orders' => (int) $item->order_count,
            ])
            ->toArray();

        // Low Stock Items (< 5 units)
        $lowStockItems = Item::withoutGlobalScopes()
            ->where('store_id', $storeId)
            ->where('status', 1)
            ->where('stock', '<=', 5)
            ->get(['id', 'name', 'stock', 'price'])
            ->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'stock' => (int) $item->stock,
                'price' => (float) $item->price,
            ])
            ->toArray();

        // Build WhatsApp Message
        $stars = str_repeat('⭐', (int) round($avgRating)) ?: '⭐';
        $formatted = "📊 *Store Business Insights: {$store->name}*\n\n";

        $formatted .= "*Revenue Summary (Delivered Orders):*\n";
        $formatted .= "• Today: ₦" . number_format($salesSummary['today'], 2) . "\n";
        $formatted .= "• This Week: ₦" . number_format($salesSummary['this_week'], 2) . "\n";
        $formatted .= "• This Month: ₦" . number_format($salesSummary['this_month'], 2) . "\n\n";

        $formatted .= "*Order Status:*\n";
        $formatted .= "• Today's Orders: {$orderSummary['today']}\n";
        $formatted .= "• Needs Attention (Pending): {$orderSummary['pending']}\n";
        $formatted .= "• Completed Deliveries: {$orderSummary['delivered']}\n";
        $formatted .= "• Cancelled / Rejected: {$orderSummary['canceled']}\n\n";

        $formatted .= "*Customer Ratings:*\n";
        $formatted .= "• Overall: {$ratingSummary['average']} / 5.0 ({$ratingSummary['total']} reviews) {$stars}\n";

        if (!empty($lowStockItems)) {
            $formatted .= "\n⚠️ *Low Stock Alert:* (" . count($lowStockItems) . " items)\n";
            foreach (array_slice($lowStockItems, 0, 3) as $l) {
                $formatted .= "• {$l['name']}: only {$l['stock']} left!\n";
            }
        }

        if (!empty($topProducts)) {
            $formatted .= "\n🏆 *Top Performing Products:*\n";
            foreach ($topProducts as $idx => $p) {
                $num = $idx + 1;
                $formatted .= "{$num}. {$p['name']} (₦" . number_format($p['price'], 2) . ") — {$p['orders']} sold\n";
            }
        }

        return [
            'sales_summary' => $salesSummary,
            'order_summary' => $orderSummary,
            'rating_summary' => $ratingSummary,
            'top_products' => $topProducts,
            'low_stock_items' => $lowStockItems,
            'formatted_message' => $formatted,
        ];
    }
}
