<?php

namespace Modules\WhatsAppVendorConcierge\app\Agents\Tools;

use App\Models\Item;
use App\Models\Order;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class GetStoreAnalyticsTool extends BaseVendorTool
{
    public function description(): string
    {
        return 'Get comprehensive store analytics including total products, active products, low stock alerts, recent performance, and ratings overview.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'period' => $schema->string()->description('Period for recent performance: "today", "this_week", "this_month" (default "this_month")')->required()->nullable(),
        ];
    }

    public function handle(Request $request): string
    {
        $this->recordTool('GetStoreAnalyticsTool');

        $store = $this->requireStore();
        $args = $request->all();
        $period = $args['period'] ?? 'this_month';

        // Product stats
        $totalProducts = Item::where('store_id', $store->id)->count();
        $activeProducts = Item::where('store_id', $store->id)->where('status', 1)->count();
        $lowStockProducts = Item::where('store_id', $store->id)->where('status', 1)->where('stock', '<', 10)->where('stock', '>', 0)->count();
        $outOfStockProducts = Item::where('store_id', $store->id)->where('status', 1)->where('stock', '<=', 0)->count();

        // Order stats for period
        $orderQuery = Order::where('store_id', $store->id);
        match ($period) {
            'today' => $orderQuery->whereDate('created_at', now()),
            'this_week' => $orderQuery->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()]),
            'this_month' => $orderQuery->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year),
            default => $orderQuery->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year),
        };

        $periodOrders = $orderQuery->get(['order_amount', 'order_status', 'created_at']);
        $periodRevenue = $periodOrders->where('payment_status', 'paid')->sum('order_amount');
        $periodOrderCount = $periodOrders->count();
        $pendingOrders = $periodOrders->where('order_status', 'pending')->count();
        $deliveredOrders = $periodOrders->where('order_status', 'delivered')->count();

        // Rating stats
        $avgRating = $store->rating_count > 0 ? round($store->rating, 1) : 0;
        $ratingCount = $store->rating_count;

        // Top categories
        $topCategories = Item::where('store_id', $store->id)
            ->where('status', 1)
            ->with('category:id,name')
            ->selectRaw('category_id, COUNT(*) as product_count, SUM(order_count) as total_orders')
            ->groupBy('category_id')
            ->orderByDesc('total_orders')
            ->limit(5)
            ->get();

        $lines = [
            "**Store Analytics: {$store->name}**",
            "",
            "**📦 Product Inventory**",
            "• Total Products: {$totalProducts}",
            "• Active: {$activeProducts}",
            "• Low Stock (<10): {$lowStockProducts}" . ($lowStockProducts > 0 ? ' ⚠️' : ''),
            "• Out of Stock: {$outOfStockProducts}" . ($outOfStockProducts > 0 ? ' 🔴' : ''),
            "",
            "**📊 Performance ($period)**",
            "• Revenue: {$this->formatNaira($periodRevenue)}",
            "• Orders: {$periodOrderCount}",
            "• Pending: {$pendingOrders}",
            "• Delivered: {$deliveredOrders}",
            "",
            "**⭐ Ratings**",
            "• Average: {$avgRating} / 5.0",
            "• Reviews: {$ratingCount}",
        ];

        if ($topCategories->isNotEmpty()) {
            $lines[] = "";
            $lines[] = "**📂 Top Categories**";
            foreach ($topCategories as $cat) {
                $catName = $cat->category->name ?? 'Unknown';
                $lines[] = "• {$catName}: {$cat->product_count} products, {$cat->total_orders} orders";
            }
        }

        // Low stock alert
        if ($lowStockProducts > 0 || $outOfStockProducts > 0) {
            $lines[] = "";
            $lines[] = "⚠️ **Attention:** You have products needing restocking. Use \"my products\" with filter \"low_stock\" to see them.";
        }

        return implode("\n", $lines);
    }
}