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
        return 'Please complete product and order changes in your secure vendor dashboard: '.rtrim(config('app.url'), '/').'/vendor-panel';
    }
}
