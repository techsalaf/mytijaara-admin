<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use Illuminate\Support\Facades\DB;

/** Read-only, explicitly store-scoped view of canonical product moderation. */
class ProductModerationStatusService
{
    public function products(int $storeId, ?int $itemId = null): array
    {
        $items = DB::table('items')->where('store_id', $storeId)
            ->when($itemId !== null, fn ($query) => $query->where('id', $itemId))
            ->orderByDesc('updated_at')->orderByDesc('id')->limit(10)
            ->get(['id', 'name', 'is_approved', 'status', 'updated_at']);
        $reviews = DB::table('temp_products')->where('store_id', $storeId)
            ->whereIn('item_id', $items->pluck('id'))->orderByDesc('id')->get()->unique('item_id')->keyBy('item_id');

        return $items->map(function ($item) use ($reviews) {
            $review = $reviews->get($item->id);
            $status = $review
                ? ($review->is_rejected ? 'Needs correction' : 'Under review')
                : ((int) $item->is_approved === 1 ? 'Approved' : 'Awaiting review');
            $reason = $review && $review->is_rejected
                ? mb_substr(trim(strip_tags((string) $review->note)), 0, 500) : '';
            return [
                'id' => (int) $item->id,
                'name' => $item->name,
                'status' => $status,
                'message' => "📦 *{$item->name}*\n\n• Product ID: *#{$item->id}*\n• Review: *{$status}*\n• Listing switch: ".($item->status ? 'Enabled' : 'Paused')
                    .($review && (int) $item->is_approved === 1 ? "\n• Your existing version remains approved while this change is reviewed." : '')
                    .($reason !== '' ? "\n\n📝 *Correction requested:*\n{$reason}" : '')
                    ."\n\n🕒 Last updated: ".($review->updated_at ?? $item->updated_at)
                    ."\n\nApproval alone does not guarantee ordering availability; stock and shop settings still apply.",
                'edit_parameters' => $review ? ['id' => $review->id, 'temp_product' => 1] : ['id' => $item->id],
            ];
        })->all();
    }
}
