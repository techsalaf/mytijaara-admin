<?php

namespace Modules\WhatsAppVendorConcierge\app\Agents\Tools;

use App\Models\Order;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class UpdateOrderStatusTool extends BaseVendorTool
{
    public function description(): string
    {
        return 'Update an order\'s status. Requires order ID and new status. Valid statuses: "confirmed", "processing", "picked_up", "delivered", "cancelled".';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'order_id' => $schema->number()->description('Order ID (internal ID) - required')->required(),
            'status' => $schema->string()->description('New status: "confirmed", "processing", "picked_up", "delivered", "cancelled"')->required(),
            'confirm' => $schema->boolean()->description('Confirmation flag for destructive actions (required for cancel/delivered)')->required()->nullable(),
        ];
    }

    public function handle(Request $request): string
    {
        $this->recordTool('UpdateOrderStatusTool');

        $vendor = $this->requireVendor();
        $args = $request->all();
        $orderId = $args['order_id'] ?? null;
        $newStatus = $args['status'] ?? null;
        $confirm = $args['confirm'] ?? false;

        if (!$orderId || !$newStatus) {
            return "I need both the order ID and the new status. Valid statuses: confirmed, processing, picked_up, delivered, cancelled.";
        }

        $validStatuses = ['confirmed', 'processing', 'picked_up', 'delivered', 'cancelled'];
        if (!in_array($newStatus, $validStatuses)) {
            return "Invalid status '$newStatus'. Valid options: " . implode(', ', $validStatuses);
        }

        $order = Order::whereHas('store', fn($q) => $q->where('vendor_id', $vendor->id))
            ->find($orderId);

        if (!$order) {
            return "Order with ID {$orderId} not found in your store.";
        }

        $currentStatus = $order->order_status;

        if ($currentStatus === $newStatus) {
            return "Order #{$order->order_id} is already {$newStatus}.";
        }

        // Validate status transitions
        $invalidTransitions = [
            'delivered' => ['cancelled', 'processing', 'picked_up', 'confirmed'],
            'cancelled' => ['delivered', 'picked_up', 'processing', 'confirmed'],
        ];

        if (isset($invalidTransitions[$currentStatus]) && in_array($newStatus, $invalidTransitions[$currentStatus])) {
            return "Cannot change status from '{$currentStatus}' to '{$newStatus}'. The order is already {$currentStatus}.";
        }

        // Require confirmation for final states
        if (in_array($newStatus, ['delivered', 'cancelled']) && !$confirm) {
            return "⚠️ **Confirmation Required**\n\n" .
                   "Are you sure you want to mark order #{$order->order_id} as **{$newStatus}**?\n\n" .
                   "Current status: {$currentStatus}\n\n" .
                   "This action cannot be undone. Reply with \"Yes, confirm\" and I'll proceed, or provide confirm=true in the tool call.";
        }

        try {
            $order->update(['order_status' => $newStatus]);

            $this->context->addResponse('order_status_updated', [
                'order_id' => $order->id,
                'old_status' => $currentStatus,
                'new_status' => $newStatus,
            ]);

            $statusIcon = match ($newStatus) {
                'confirmed' => '✅',
                'processing' => '🔄',
                'picked_up' => '📦',
                'delivered' => '🎉',
                'cancelled' => '❌',
                default => '📋',
            };

            return "✅ **Order Status Updated!**\n\n" .
                   "Order #{$order->order_id}\n" .
                   "Previous: {$currentStatus} → New: {$statusIcon} {$newStatus}\n\n" .
                   ($newStatus === 'delivered' ? '🎉 Great! The order has been delivered.' : '') .
                   ($newStatus === 'cancelled' ? '❌ Order has been cancelled.' : '');
        } catch (\Throwable $e) {
            return "Failed to update order status: " . $e->getMessage();
        }
    }
}