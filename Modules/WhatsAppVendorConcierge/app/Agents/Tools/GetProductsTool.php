<?php

namespace Modules\WhatsAppVendorConcierge\app\Agents\Tools;

use App\Models\Item;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class GetProductsTool extends BaseVendorTool
{
    public function description(): string
    {
        return 'List the vendor\'s products/items with details like name, price, stock, and status. Returns up to 50 products ordered by most recent.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'filter' => $schema->string()->description('Filter by status: "active" (default), "inactive", "low_stock" (stock < 10), or "all"')->required()->nullable(),
            'search' => $schema->string()->description('Search products by name (partial match)')->required()->nullable(),
            'limit' => $schema->number()->description('Maximum number of products to return (default 20, max 50)')->required()->nullable(),
        ];
    }

    public function handle(Request $request): string
    {
        $this->recordTool('GetProductsTool');

        $store = $this->requireStore();
        $args = $request->all();
        $filter = $args['filter'] ?? 'active';
        $search = $args['search'] ?? null;
        $limit = min((int) ($args['limit'] ?? 20), 50);

        $query = Item::where('store_id', $store->id);

        match ($filter) {
            'active' => $query->where('status', 1),
            'inactive' => $query->where('status', 0),
            'low_stock' => $query->where('stock', '<', 10),
            'all' => null,
            default => $query->where('status', 1),
        };

        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }

        $items = $query->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'name', 'price', 'discount', 'discount_type', 'stock', 'status', 'avg_rating', 'order_count']);

        if ($items->isEmpty()) {
            return match ($filter) {
                'low_stock' => "You don't have any low-stock products. Your inventory is healthy! 🎉",
                'inactive' => "You don't have any inactive products.",
                'active' => "You don't have any active products yet. Want to add your first one?",
                default => "You don't have any products yet. Want to add your first one?",
            };
        }

        $this->context->addProducts($items->map(fn($item) => [
            'id' => $item->id,
            'name' => $item->name,
            'price' => $item->price,
            'stock' => $item->stock,
            'status' => $item->status,
            'avg_rating' => $item->avg_rating,
        ])->toArray());

        $lines = ["**Your Products ({$items->count()}):**", ""];

        foreach ($items as $item) {
            $price = $item->discount > 0
                ? ($item->discount_type === 'percent'
                    ? round($item->price - ($item->price * $item->discount / 100), 2)
                    : $item->price - $item->discount)
                : $item->price;

            $statusIcon = $item->status ? '✅' : '❌';
            $stockIcon = match (true) {
                $item->stock <= 0 => '⚠️ Out',
                $item->stock < 10 => '🔻 Low',
                default => "📦 {$item->stock}",
            };
            $rating = $item->avg_rating > 0 ? " ⭐ " . round($item->avg_rating, 1) : "";

            $lines[] = "• {$item->name} [ID:{$item->id}] — {$this->formatNaira($price)} — {$stockIcon}{$rating} {$statusIcon}";
        }

        if ($items->count() === $limit) {
            $lines[] = "";
            $lines[] = "_Showing first {$limit} products. Ask me to search for specific items to see more._";
        }

        return implode("\n", $lines);
    }
}