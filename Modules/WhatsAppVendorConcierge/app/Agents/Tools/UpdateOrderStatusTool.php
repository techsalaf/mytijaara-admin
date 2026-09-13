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
        ];
    }

    public function handle(Request $request): string
    {
        return 'Please complete product and order changes in your secure vendor dashboard: '.rtrim(config('app.url'), '/').'/vendor-panel';
    }
}
