<?php

namespace Modules\WhatsAppVendorConcierge\app\Agents\Tools;

use App\Models\Item;
use App\Models\Category;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class UpdateProductTool extends BaseVendorTool
{
    public function description(): string
    {
        return 'Update an existing product. Requires product ID. Can update name, price, stock, discount, category, description, status, etc.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->number()->description('Product ID to update (required)')->required(),
            'name' => $schema->string()->description('New product name')->required()->nullable(),
            'price' => $schema->number()->description('New price in Naira')->required()->nullable(),
            'category_id' => $schema->number()->description('New category ID')->required()->nullable(),
            'description' => $schema->string()->description('New description')->required()->nullable(),
            'stock' => $schema->number()->description('New stock quantity')->required()->nullable(),
            'discount' => $schema->number()->description('New discount amount')->required()->nullable(),
            'discount_type' => $schema->string()->description('Discount type: "percent" or "amount"')->required()->nullable(),
            'status' => $schema->boolean()->description('Activate (true) or deactivate (false)')->required()->nullable(),
            'veg' => $schema->boolean()->description('Is vegetarian')->required()->nullable(),
            'recommended' => $schema->boolean()->description('Mark as recommended')->required()->nullable(),
        ];
    }

    public function handle(Request $request): string
    {
        $this->recordTool('UpdateProductTool');

        $store = $this->requireStore();
        $args = $request->all();
        $productId = $args['product_id'] ?? null;

        if (!$productId) {
            return "I need the product ID to update. Please provide the product ID (you can find it in your product list).";
        }

        $item = Item::where('id', $productId)
            ->where('store_id', $store->id)
            ->first();

        if (!$item) {
            return "Product with ID {$productId} not found in your store.";
        }

        // Build update data
        $updateData = [];

        if (isset($args['name'])) $updateData['name'] = $args['name'];
        if (isset($args['price'])) $updateData['price'] = (float) $args['price'];
        if (isset($args['category_id'])) {
            $category = Category::where('id', $args['category_id'])
                ->where('module_id', $store->module_id)
                ->where('status', 1)
                ->first();
            if (!$category) {
                return "Category ID {$args['category_id']} not found or not available for your module.";
            }
            $updateData['category_id'] = $args['category_id'];
        }
        if (isset($args['description'])) $updateData['description'] = $args['description'];
        if (isset($args['stock'])) $updateData['stock'] = (int) $args['stock'];
        if (isset($args['discount'])) $updateData['discount'] = (float) $args['discount'];
        if (isset($args['discount_type'])) $updateData['discount_type'] = $args['discount_type'];
        if (isset($args['status'])) $updateData['status'] = (bool) $args['status'];
        if (isset($args['veg'])) $updateData['veg'] = (bool) $args['veg'];
        if (isset($args['recommended'])) $updateData['recommended'] = (bool) $args['recommended'];

        if (empty($updateData)) {
            return "No changes provided. Please specify what you'd like to update.";
        }

        try {
            $item->update($updateData);

            // Get fresh data for response
            $item->refresh();

            $price = $item->discount > 0
                ? ($item->discount_type === 'percent'
                    ? round($item->price - ($item->price * $item->discount / 100), 2)
                    : $item->price - $item->discount)
                : $item->price;

            $this->context->addResponse('updated_product', [
                'id' => $item->id,
                'name' => $item->name,
            ]);

            $changes = [];
            foreach ($updateData as $key => $value) {
                $label = match ($key) {
                    'name' => 'Name',
                    'price' => 'Price',
                    'stock' => 'Stock',
                    'discount' => 'Discount',
                    'discount_type' => 'Discount Type',
                    'status' => 'Status',
                    'category_id' => 'Category',
                    'description' => 'Description',
                    'veg' => 'Vegetarian',
                    'recommended' => 'Recommended',
                    default => ucfirst($key),
                };
                $changes[] = "• {$label}: " . (is_bool($value) ? ($value ? 'Yes' : 'No') : $value);
            }

            return "✅ **Product Updated Successfully!**\n\n" .
                   "**{$item->name}** [ID:{$item->id}]\n" .
                   "💰 Price: {$this->formatNaira($price)}\n" .
                   "📦 Stock: {$item->stock}\n" .
                   "📊 Status: " . ($item->status ? 'Active ✅' : 'Inactive ❌') . "\n\n" .
                   "**Changes made:**\n" . implode("\n", $changes);
        } catch (\Throwable $e) {
            return "Failed to update product: " . $e->getMessage();
        }
    }
}