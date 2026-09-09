<?php

namespace Modules\WhatsAppVendorConcierge\app\Agents\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class ResumeShopTool extends BaseVendorTool
{
    public function description(): string
    {
        return 'Resume/open the vendor\'s shop (set active to true). Customers can place orders again.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'confirm' => $schema->boolean()->description('Must be true to confirm resuming the shop')->required()->nullable(),
        ];
    }

    public function handle(Request $request): string
    {
        $this->recordTool('ResumeShopTool');

        $store = $this->requireStore();
        $args = $request->all();
        $confirm = $args['confirm'] ?? false;

        if ($store->active) {
            return "Your shop is already open and accepting orders.";
        }

        if (!$confirm) {
            return "⚠️ **Confirm Shop Resume**\n\n" .
                   "Are you sure you want to **open** your shop **{$store->name}**?\n\n" .
                   "• Customers will see your shop in the marketplace\n" .
                   "• New orders can be placed\n" .
                   "• Make sure you're ready to fulfill orders\n\n" .
                   "Reply with \"Yes, open my shop\" to confirm, or call this tool with confirm=true.";
        }

        try {
            $store->update(['active' => true]);

            $this->context->addResponse('shop_resumed', [
                'store_id' => $store->id,
                'previous_status' => false,
            ]);

            return "✅ **Shop Opened Successfully!** 🎉\n\n" .
                   "**{$store->name}** is now **open** 🟢\n\n" .
                   "Your shop is visible to customers and accepting new orders.\n" .
                   "Good luck with your sales!";
        } catch (\Throwable $e) {
            return "Failed to open shop: " . $e->getMessage();
        }
    }
}