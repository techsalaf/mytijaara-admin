<?php

namespace Modules\WhatsAppVendorConcierge\app\Agents\Tools;

use App\Models\Item;
use App\Models\Category;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class CreateProductTool extends BaseVendorTool
{
    public function description(): string
    {
        return 'Create a new product for the vendor\'s store. Requires product name, price, and category. Optional: description, stock, discount, image.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Product name (required)')->required(),
            'price' => $schema->number()->description('Product price in Naira (required)')->required(),
            'category_id' => $schema->number()->description('Category ID (required - use GetCategoriesTool to find)')->required(),
            'description' => $schema->string()->description('Product description')->required()->nullable(),
            'stock' => $schema->number()->description('Initial stock quantity (default 10)')->required()->nullable(),
            'discount' => $schema->number()->description('Discount amount')->required()->nullable(),
            'discount_type' => $schema->string()->description('Discount type: "percent" or "amount"')->required()->nullable(),
            'image' => $schema->string()->description('Image URL or media ID (optional)')->required()->nullable(),
            'veg' => $schema->boolean()->description('Is vegetarian (for food modules)')->required()->nullable(),
            'recommended' => $schema->boolean()->description('Mark as recommended')->required()->nullable(),
            'confirm' => $schema->boolean()->description('Confirmation flag (must be true to create the product)')->required()->nullable(),
        ];
    }

    public function handle(Request $request): string
    {
        $this->recordTool('CreateProductTool');

        $store = $this->requireStore();
        $args = $request->all();

        $name = $args['name'] ?? null;
        $price = $args['price'] ?? null;
        $categoryId = $args['category_id'] ?? null;
        $confirm = $args['confirm'] ?? false;

        if (!$name || !$price || !$categoryId) {
            // Ask for missing info
            $missing = [];
            if (!$name) $missing[] = 'product name';
            if (!$price) $missing[] = 'price';
            if (!$categoryId) $missing[] = 'category';

            return "I need a few details to create the product:\n" .
                   implode(', ', $missing) . " are required.\n\n" .
                   "Please provide:\n" .
                   "• Product name\n" .
                   "• Price in Naira (e.g., 2500)\n" .
                   "• Category (I can help you find the right category ID)";
        }

        // Verify category exists and belongs to store's module
        $category = Category::where('id', $categoryId)
            ->where('module_id', $store->module_id)
            ->where('status', 1)
            ->first();

        if (!$category) {
            return "Category ID {$categoryId} not found or not available for your store's module. Please check with GetCategoriesTool.";
        }

        // Check confirmation guard if write verification is enabled
        if (config('whatsapp-vendor-concierge.security.require_verification_for_writes', true) && !$confirm) {
            $stock = (int) ($args['stock'] ?? 10);
            return "⚠️ **Confirm New Product Listing**\n\n" .
                   "Please review the product details before I add it to your shop:\n\n" .
                   "• **Product Name:** {$name}\n" .
                   "• **Price:** {$this->formatNaira($price)}\n" .
                   "• **Category:** {$category->name}\n" .
                   "• **Initial Stock:** {$stock}\n\n" .
                   "Reply with \"Yes, create product\" or \"Confirm\" to publish it to your shop catalog!";
        }

        try {
            $item = Item::create([
                'store_id' => $store->id,
                'module_id' => $store->module_id,
                'category_id' => $categoryId,
                'name' => $name,
                'price' => (float) $price,
                'description' => $args['description'] ?? '',
                'stock' => (int) ($args['stock'] ?? 10),
                'discount' => (float) ($args['discount'] ?? 0),
                'discount_type' => $args['discount_type'] ?? 'percent',
                'image' => $args['image'] ?? '',
                'veg' => $args['veg'] ?? false,
                'recommended' => $args['recommended'] ?? false,
                'status' => 1,
            ]);

            $this->context->addResponse('created_product', [
                'id' => $item->id,
                'name' => $item->name,
                'price' => $item->price,
            ]);

            return "✅ **Product Created Successfully!**\n\n" .
                   "**{$item->name}** [ID:{$item->id}]\n" .
                   "💰 Price: {$this->formatNaira($item->price)}\n" .
                   "📦 Stock: {$item->stock}\n" .
                   "📂 Category: {$category->name}\n" .
                   "📊 Status: Active ✅\n\n" .
                   "Your product is now live in your shop!";
        } catch (\Throwable $e) {
            return "Failed to create product: " . $e->getMessage();
        }
    }
}