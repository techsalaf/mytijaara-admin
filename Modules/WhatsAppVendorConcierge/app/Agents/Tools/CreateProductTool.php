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
            'category_id' => $schema->number()->description('Category ID from your store module')->required(),
            'description' => $schema->string()->description('Product description')->required()->nullable(),
            'stock' => $schema->number()->description('Initial stock quantity (default 10)')->required()->nullable(),
            'discount' => $schema->number()->description('Discount amount')->required()->nullable(),
            'discount_type' => $schema->string()->description('Discount type: "percent" or "amount"')->required()->nullable(),
            'image' => $schema->string()->description('Image URL or media ID (optional)')->required()->nullable(),
            'veg' => $schema->boolean()->description('Is vegetarian (for food modules)')->required()->nullable(),
            'recommended' => $schema->boolean()->description('Mark as recommended')->required()->nullable(),
        ];
    }

    public function handle(Request $request): string
    {
        $this->recordTool('CreateProductTool');

        $data = [
            'name' => $request['name'],
            'price' => (float) $request['price'],
            'category_id' => (int) $request['category_id'],
            'description' => $request['description'] ?? null,
            'stock' => isset($request['stock']) ? (int) $request['stock'] : 10,
            'discount' => isset($request['discount']) ? (float) $request['discount'] : 0,
            'discount_type' => $request['discount_type'] ?? 'percent',
            'image' => $request['image'] ?? 'def.png',
            'veg' => !empty($request['veg']),
        ];

        return $this->prepareProductCreate($data);
    }
}
