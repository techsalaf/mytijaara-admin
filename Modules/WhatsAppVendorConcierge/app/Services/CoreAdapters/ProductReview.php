<?php
namespace Modules\WhatsAppVendorConcierge\app\Services\CoreAdapters;

use App\CentralLogics\Helpers;
use App\Models\BusinessSetting;
use App\Models\Item;
use App\Models\TempProduct;
use App\Models\Translation;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Maintained port of the host TempProduct review boundary for concierge edits. */
class ProductReview
{
    public function stage(Item $item, string $operation): ?TempProduct
    {
        $rules = json_decode(BusinessSetting::where('key', 'product_approval_datas')->value('value') ?: '{}', true);
        if (!Helpers::get_mail_status('product_approval') || !(($rules[$operation] ?? false) ||
            ($operation !== 'Add_new_product' && ($rules['Update_anything_in_product_details'] ?? false)))) return null;

        $draft = TempProduct::firstOrNew(['item_id' => $item->id]);
        $newDraft = !$draft->exists;
        foreach (['name', 'description', 'store_id', 'module_id', 'unit_id', 'category_id', 'category_ids',
            'store_category_id', 'slug', 'choice_options', 'food_variations', 'variations', 'add_ons',
            'attributes', 'price', 'discount', 'discount_type', 'available_time_starts', 'available_time_ends',
            'maximum_cart_quantity', 'veg', 'organic', 'is_halal', 'stock', 'video', 'video_link'] as $field) {
            if (!$draft->exists && array_key_exists($field, $item->getAttributes())) $draft->setAttribute($field, $item->getRawOriginal($field));
            // getRawOriginal excludes the proposed unsaved price/name changes.
            if ($item->isDirty($field)) $draft->setAttribute($field, $item->getAttributes()[$field]);
        }
        $draft->item_id = $item->id;
        $draft->is_rejected = 0;
        foreach (['tag_ids' => 'tags', 'nutrition_ids' => 'nutritions', 'allergy_ids' => 'allergies', 'generic_ids' => 'generic'] as $field => $relation) {
            if (!$draft->exists) $draft->$field = json_encode($item->$relation()->allRelatedIds()->all(), JSON_THROW_ON_ERROR);
        }
        if ($item->image && ($newDraft || $item->isDirty('image'))) {
            $disk = $item->isDirty('image') ? Helpers::getDisk()
                : ($item->storage->firstWhere('key', 'image')?->value ?? $item->storage->first()?->value ?? Helpers::getDisk());
            $draft->image = $item->image === 'def.png' ? 'def.png' : $this->copyImage($item->image, $disk);
        }
        if ($newDraft && array_key_exists('images', $item->getAttributes())) {
            $draft->images = array_map(function ($image) {
                $image = is_array($image) ? $image : ['img' => $image, 'storage' => 'public'];
                $image['img'] = $this->copyImage($image['img'], $image['storage'] ?? 'public');
                $image['storage'] = Helpers::getDisk();
                return $image;
            }, $item->images ?? []);
        }
        $draft->save();
        if ($newDraft) {
            // Admin approval replaces these relations from the review record.
            // Preserve the current values for a price-only edit.
            $module = $item->module->module_type;
            if ($module === 'pharmacy') $this->copyDetails('pharmacy_item_details', 'temp_product_id', $item->id, $draft->id);
            if (in_array($module, ['ecommerce', 'grocery'], true)) $this->copyDetails('ecommerce_item_details', 'temp_product_id', $item->id, $draft->id);
            if ($module === 'ecommerce') $this->copyDetails('item_seo_data', 'temp_item_id', $item->id, $draft->id);
            if (addon_published_status('TaxModule')) {
                foreach ($item->taxVats as $tax) {
                    $copy = $tax->replicate();
                    $copy->taxable_type = TempProduct::class;
                    $copy->taxable_id = $draft->id;
                    $copy->save();
                }
            }
        }
        // The Item translation global scope preloads only the current locale.
        // Query the relation explicitly so review preserves every language.
        foreach ($item->translations()->get() as $translation) {
            Translation::firstOrCreate([
                'translationable_type' => TempProduct::class,
                'translationable_id' => $draft->id,
                'locale' => $translation->locale,
                'key' => $translation->key,
            ], ['value' => $translation->value]);
        }
        foreach (['name', 'description'] as $field) {
            if ($item->isDirty($field)) Translation::updateOrCreate([
                'translationable_type' => TempProduct::class,
                'translationable_id' => $draft->id,
                'locale' => 'en', 'key' => $field,
            ], ['value' => $item->$field]);
        }
        return $draft;
    }

    private function copyImage(string $filename, string $disk): string
    {
        if (basename($filename) !== $filename || !Storage::disk($disk)->exists('product/'.$filename)) {
            throw ValidationException::withMessages(['image' => ['An existing product image is unavailable. Restore it before requesting review.']]);
        }
        $copy = 'review-'.bin2hex(random_bytes(16)).'.'.pathinfo($filename, PATHINFO_EXTENSION);
        if (!Storage::disk(Helpers::getDisk())->put('product/'.$copy, Storage::disk($disk)->get('product/'.$filename))) {
            throw new \RuntimeException('Could not preserve product review image');
        }
        return $copy;
    }

    private function copyDetails(string $table, string $reviewColumn, int $itemId, int $draftId): void
    {
        $row = DB::table($table)->where('item_id', $itemId)->first();
        if (!$row) return;
        $copy = (array) $row;
        unset($copy['id']);
        $copy['item_id'] = null;
        $copy[$reviewColumn] = $draftId;
        DB::table($table)->insert($copy);
    }
}
