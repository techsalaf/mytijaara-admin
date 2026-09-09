<?php

namespace Modules\WhatsAppVendorConcierge\app\Agents\Tools;

use App\Models\Order;
use App\Models\OrderDetail;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class GetOrderDetailsTool extends BaseVendorTool
{
    public function description(): string
    {
        return 'Get detailed information for a specific order including items, customer info, delivery address, and payment details.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'order_id' => $schema->number()->description('Order ID (internal ID, not order_id string) - required')->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $this->recordTool('GetOrderDetailsTool');

        $vendor = $this->requireVendor();
        $args = $request->all();
        $orderId = $args['order_id'] ?? null;

        if (!$orderId) {
            return "I need the order ID to look up details. Please provide the order ID.";
        }

        $order = Order::whereHas('store', fn($q) => $q->where('vendor_id', $vendor->id))
            ->with([
                'store:id,name',
                'details' => fn($q) => $q->with('item:id,name,price'),
                'user:id,f_name,l_name,phone',
                'address:id,address,city,state,latitude,longitude',
            ])
            ->find($orderId);

        if (!$order) {
            return "Order with ID {$orderId} not found in your store.";
        }

        $this->context->addResponse('order_details', [
            'id' => $order->id,
            'order_id' => $order->order_id,
        ]);

        $statusIcon = match ($order->order_status) {
            'pending' => '⏳',
            'confirmed' => '✅',
            'processing' => '🔄',
            'picked_up' => '📦',
            'delivered' => '🎉',
            'cancelled' => '❌',
            default => '📋',
        };

        $lines = [
            "**Order #{$order->order_id}**",
            "",
            "📊 **Status:** {$statusIcon} {$order->order_status}",
            "💰 **Total:** {$this->formatNaira($order->order_amount)}",
            "🏪 **Store:** {$order->store->name}",
            "📅 **Placed:** {$order->created_at->format('M d, Y H:i')}",
            "",
            "**Customer:**",
            "• {$order->user->f_name} {$order->user->l_name}",
            "• {$order->user->phone}",
        ];

        if ($order->address) {
            $lines[] = "• {$order->address->address}" . ($order->address->city ? ", {$order->address->city}" : "") . ($order->address->state ? ", {$order->address->state}" : "");
        }

        if ($order->delivery_fee) {
            $lines[] = "";
            $lines[] = "🚚 **Delivery Fee:** {$this->formatNaira($order->delivery_fee)}";
        }

        if ($order->coupon_discount) {
            $lines[] = "🏷️ **Discount:** {$this->formatNaira($order->coupon_discount)}";
        }

        $lines[] = "";
        $lines[] = "**Items:**";

        $subtotal = 0;
        foreach ($order->details as $detail) {
            $itemTotal = $detail->price * $detail->quantity;
            $subtotal += $itemTotal;
            $lines[] = "• {$detail->item->name} × {$detail->quantity} — {$this->formatNaira($itemTotal)}";
        }

        $lines[] = "";
        $lines[] = "Subtotal: {$this->formatNaira($subtotal)}";
        $lines[] = "Total: {$this->formatNaira($order->order_amount)}";

        if ($order->payment_status) {
            $paymentIcon = $order->payment_status === 'paid' ? '✅' : '⏳';
            $lines[] = "";
            $lines[] = "💳 **Payment:** {$paymentIcon} {$order->payment_status}";
        }

        return implode("\n", $lines);
    }
}