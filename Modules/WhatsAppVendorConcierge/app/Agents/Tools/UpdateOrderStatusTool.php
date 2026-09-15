<?php

namespace Modules\WhatsAppVendorConcierge\app\Agents\Tools;

use App\Models\Order;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class UpdateOrderStatusTool extends BaseVendorTool
{
    public function description(): string
    {
        return 'Update an order status with explicit vendor confirmation. Requires order ID and target status. Valid statuses: "confirmed" (accept), "processing" (preparing), "handover" (ready for pickup), "delivered", "canceled" (reject).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'order_id' => $schema->number()->description('Order ID (required)')->required(),
            'status' => $schema->string()->description('Target status: "confirmed", "processing", "handover", "delivered", "canceled"')->required(),
            'reason' => $schema->string()->description('Cancellation reason if rejecting/cancelling order')->required()->nullable(),
            'otp' => $schema->string()->description('Delivery verification code provided by the vendor; never guess a code')->required()->nullable(),
        ];
    }

    public function handle(Request $request): string
    {
        $this->recordTool('UpdateOrderStatusTool');
        $orderId = (int) $request['order_id'];
        $status = strtolower(trim($request['status']));

        // Normalize status aliases
        if ($status === 'picked_up' || $status === 'ready_for_pickup') {
            $status = 'handover';
        }
        if ($status === 'cancelled') {
            $status = 'canceled';
        }

        $reason = $request['reason'] ?? null;

        return $this->prepareOrderStatus($orderId, $status, $reason, $request['otp'] ?? null);
    }
}
