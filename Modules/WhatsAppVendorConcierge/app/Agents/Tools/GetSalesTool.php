<?php

namespace Modules\WhatsAppVendorConcierge\app\Agents\Tools;

use App\Models\Order;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class GetSalesTool extends BaseVendorTool
{
    public function description(): string
    {
        return 'Get sales summary for the vendor. Timeframes: "today", "this_week", "this_month", "all". Returns revenue, order count, average order value, and top products.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'timeframe' => $schema->string()->description('Timeframe: "today" (default), "this_week", "this_month", "all"')->required()->nullable(),
        ];
    }

    public function handle(Request $request): string
    {
        $this->recordTool('GetSalesTool');

        $vendor = $this->requireVendor();
        $args = $request->all();
        $timeframe = $args['timeframe'] ?? 'today';

        $query = Order::whereHas('store', fn($q) => $q->where('vendor_id', $vendor->id))
            ->where('payment_status', 'paid');

        match ($timeframe) {
            'today' => $query->whereDate('created_at', now()),
            'this_week' => $query->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()]),
            'this_month' => $query->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year),
            'all' => null,
            default => $query->whereDate('created_at', now()),
        };

        $orders = $query->get(['order_amount', 'created_at']);

        if ($orders->isEmpty()) {
            $label = $timeframe === 'all' ? 'any sales' : "sales $timeframe";
            return "You don't have $label recorded yet.";
        }

        $revenue = $orders->sum('order_amount');
        $orderCount = $orders->count();
        $avgOrder = $orderCount > 0 ? $revenue / $orderCount : 0;

        // Get top products
        $topProducts = \App\Models\OrderDetail::whereHas('order', fn($q) => $q->whereHas('store', fn($q2) => $q2->where('vendor_id', $vendor->id)))
            ->when($timeframe !== 'all', function ($q) use ($timeframe) {
                match ($timeframe) {
                    'today' => $q->whereHas('order', fn($q2) => $q2->whereDate('created_at', now())),
                    'this_week' => $q->whereHas('order', fn($q2) => $q2->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])),
                    'this_month' => $q->whereHas('order', fn($q2) => $q2->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)),
                };
            })
            ->whereHas('order', fn($q) => $q->where('payment_status', 'paid'))
            ->with('item:id,name')
            ->selectRaw('item_id, SUM(price * quantity) as total_revenue, SUM(quantity) as total_qty')
            ->groupBy('item_id')
            ->orderByDesc('total_revenue')
            ->limit(5)
            ->get();

        $this->context->addSalesData([
            'timeframe' => $timeframe,
            'revenue' => $revenue,
            'order_count' => $orderCount,
            'avg_order' => $avgOrder,
            'top_products' => $topProducts->map(fn($p) => [
                'name' => $p->item->name ?? 'Unknown',
                'revenue' => $p->total_revenue,
                'quantity' => $p->total_qty,
            ])->toArray(),
        ]);

        $label = $timeframe === 'all' ? 'All Time' : ucfirst(str_replace('_', ' ', $timeframe));

        $lines = [
            "**Sales Summary ($label)**",
            "",
            "💰 **Revenue:** {$this->formatNaira($revenue)}",
            "📦 **Orders:** {$orderCount}",
            "📊 **Avg Order:** {$this->formatNaira($avgOrder)}",
        ];

        if ($topProducts->isNotEmpty()) {
            $lines[] = "";
            $lines[] = "**Top Products:**";
            foreach ($topProducts as $p) {
                $lines[] = "• {$p->item->name} — {$this->formatNaira($p->total_revenue)} ({$p->total_qty} sold)";
            }
        }

        return implode("\n", $lines);
    }
}