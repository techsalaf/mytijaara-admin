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
            'stock' => $schema->integer()->description('New total stock quantity')->required()->nullable(),
            'variation_stocks' => $schema->array()->items($schema->object([
                'type' => $schema->string()->description('Exact existing variation type')->required(),
                'stock' => $schema->integer()->description('New stock for this variation')->required(),
            ]))->description('For products with variations, supply every variation; quantities must sum to total stock')->required()->nullable(),
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
        $productId = (int) $request['product_id'];

        if (isset($request['price']) && $request['price'] !== null) {
            return $this->prepareProductPrice($productId, (float) $request['price']);
        }

        if (isset($request['stock']) && $request['stock'] !== null) {
            $stocks = null;
            if (isset($request['variation_stocks'])) {
                $rows = $request['variation_stocks'];
                $validator = validator(['rows' => $rows], ['rows' => 'array', 'rows.*.type' => 'required|string|distinct', 'rows.*.stock' => 'required|integer|min:0']);
                if ($validator->fails()) return 'Provide each existing variation once with a non-negative stock quantity.';
                $stocks = [];
                foreach ($rows as $row) $stocks[$row['type']] = (int) $row['stock'];
            }
            return $this->prepareProductStock($productId, (int) $request['stock'], $stocks);
        }

        if (isset($request['status']) && $request['status'] !== null) {
            return $this->prepareProductAvailability($productId, (bool) $request['status']);
        }

        return 'To update product details, please specify a new price, stock quantity, or active status (e.g. "Update price of product #12 to ₦2,500").';
    }
}
