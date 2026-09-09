<?php

namespace Modules\WhatsAppVendorConcierge\app\Agents\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class PauseShopTool extends BaseVendorTool
{
    public function description(): string
    {
        return 'Pause/close the vendor\'s shop (set active to false). Customers will not be able to place new orders. Requires confirmation.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'confirm' => $schema->boolean()->description('Must be true to confirm pausing the shop')->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $this->recordTool('PauseShopTool');

        $store = $this->requireStore();
        $args = $request->all();
        $confirm = $args['confirm'] ?? false;

        if (!$store->active) {
            return "Your shop is already paused/closed.";
        }

        if (!$confirm) {
            return "⚠️ **Confirm Shop Pause**\n\n" .
                   "Are you sure you want to **pause** your shop **{$store->name}**?\n\n" .
                   "• Customers won't see your shop in the marketplace\n" .
                   "• No new orders can be placed\n" .
                   "• Existing orders are not affected\n" .
                   "• You can resume anytime\n\n" .
                   "Reply with \"Yes, pause my shop\" to confirm, or call this tool with confirm=true.";
        }

        try {
            $store->update(['active' => false]);

            $this->context->addResponse('shop_paused', [
                'store_id' => $store->id,
                'previous_status' => true,
            ]);

            return "✅ **Shop Paused Successfully**\n\n" .
                   "**{$store->name}** is now **closed** 🔴\n\n" .
                   "Your shop is hidden from customers. Existing orders will continue normally.\n" .
                   "Say \"open my shop\" or \"resume shop\" when you're ready to accept orders again.";
        } catch (\Throwable $e) {
            return "Failed to pause shop: " . $e->getMessage();
        }
    }
}