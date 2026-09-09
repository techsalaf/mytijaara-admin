<?php

namespace Modules\WhatsAppVendorConcierge\app\Agents\Tools;

use App\Models\Order;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class GetOrdersTool extends BaseVendorTool
{
    public function description(): string
    {
        return 'Get vendor\'s orders. Can filter by timeframe: "today", "this_week", "this_month", or "all". Returns order ID, amount, status, and time.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'timeframe' => $schema->string()->description('Timeframe: "today" (default), "this_week", "this_month", or "all"')->required()->nullable(),
            'status' => $schema->string()->description('Filter by order status: "pending", "confirmed", "processing", "picked_up", "delivered", "cancelled"')->required()->nullable(),
            'limit' => $schema->number()->description('Max orders to return (default 20, max 50)')->required()->nullable(),
        ];
    }

    public function handle(Request $request): string
    {
        $this->recordTool('GetOrdersTool');

        $vendor = $this->requireVendor();
        $args = $request->all();
        $timeframe = $args['timeframe'] ?? 'today';
        $status = $args['status'] ?? null;
        $limit = min((int) ($args['limit'] ?? 20), 50);

        $query = Order::whereHas('store', fn($q) => $q->where('vendor_id', $vendor->id));

        match ($timeframe) {
            'today' => $query->whereDate('created_at', now()),
            'this_week' => $query->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()]),
            'this_month' => $query->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year),
            'all' => null,
            default => $query->whereDate('created_at', now()),
        };

        if ($status) {
            $query->where('order_status', $status);
        }

        $orders = $query->with('store:id,name')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'order_id', 'order_status', 'order_amount', 'created_at', 'store_id']);

        if ($orders->isEmpty()) {
            $label = $timeframe === 'all' ? 'any orders' : "orders $timeframe";
            return "You don't have $label" . ($status ? " with status '$status'" : '') . ".";
        }

        $this->context->addOrders($orders->map(fn($o) => [
            'id' => $o->id,
            'order_id' => $o->order_id,
            'status' => $o->order_status,
            'amount' => $o->order_amount,
            'created_at' => $o->created_at,
        ])->toArray());

        $label = $timeframe === 'all' ? 'All Orders' : ucfirst(str_replace('_', ' ', $timeframe)) . ' Orders';
        $lines = ["**{$label} ({$orders->count()}):**", ""];

        foreach ($orders as $order) {
            $statusIcon = match ($order->order_status) {
                'pending' => '⏳',
                'confirmed' => '✅',
                'processing' => '🔄',
                'picked_up' => '📦',
                'delivered' => '🎉',
                'cancelled' => '❌',
                default => '📋',
            };
            $timeAgo = $order->created_at->diffForHumans();
            $lines[] = "• #{$order->order_id} — {$this->formatNaira($order->order_amount)} — {$statusIcon} {$order->order_status} — {$timeAgo}";
        }

        if ($orders->count() === $limit) {
            $lines[] = "";
            $lines[] = "_Showing latest {$limit} orders. Use filters to narrow down._";
        }

        return implode("\n", $lines);
    }
}