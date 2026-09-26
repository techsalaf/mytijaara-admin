<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use App\CentralLogics\Helpers;
use App\Models\Category;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ProductFieldMap
{
    const TYPES = ['grocery', 'ecommerce', 'food', 'pharmacy'];

    public function steps(Store $store, array $data = []): array
    {
        if (! in_array($store->module->module_type, self::TYPES, true)) {
            return [];
        }
        $type = $store->module->module_type;
        $steps = ['image', 'name', 'category_id', 'subcategory_id', 'description'];
        if (Helpers::storeCategoryStatus() && DB::table('store_categories')->where('store_id', $store->id)->exists()) {
            $steps[] = 'store_category_id';
        }
        if (config("module.$type.unit")) {
            $steps[] = 'unit_id';
        }
        $steps = array_merge($steps, ['price', 'discount']);
        if (config("module.$type.stock")) {
            $steps[] = 'stock';
        }
        if ($type === 'food') {
            $steps = array_merge($steps, ['veg', 'available_time_starts', 'available_time_ends', 'add_ons']);
        }
        if ($type === 'pharmacy') {
            $steps[] = 'is_prescription_required';
        }
        if ($type === 'food') {
            $steps[] = 'food_group_count';
            for ($i = 0; $i < ($data['food_group_count'] ?? 0); $i++) {
                foreach (['name', 'required', 'min', 'max', 'options'] as $part) {
                    $steps[] = 'food_'.$i.'_'.$part;
                }
                foreach ($data['food_'.$i.'_options'] ?? [] as $j => $option) {
                    $steps[] = 'food_'.$i.'_price_'.$j;
                }
            }
        }
        if ($type !== 'food') {
            $steps[] = 'attribute_ids';
            foreach ($data['attribute_ids'] ?? [] as $id) {
                $steps[] = 'choice_'.$id;
            }
            foreach ($this->combinations($data) as $i => $label) {
                $steps[] = 'variant_price_'.$i;
                $steps[] = 'variant_stock_'.$i;
            }
        }
        if (Schema::hasTable('system_tax_setups') && Helpers::getTaxSystemType(false)['productWiseTax']) {
            $steps[] = 'tax_ids';
        }
        $steps[] = 'extra_details';
        if (! empty($data['extra_details'])) {
            $steps[] = 'tags';
            foreach (['brand' => 'brand_id', 'organic' => 'organic', 'nutrition' => 'nutritions', 'allergy' => 'allergies', 'common_condition' => 'condition_id', 'generic_name' => 'generic_name', 'basic' => 'basic'] as $cap => $field) {
                if (config("module.$type.$cap")) {
                    $steps[] = $field;
                }
            }
            if ($type === 'pharmacy') {
                $steps = array_merge($steps, ['manufacturer', 'unit_value']);
            }
        }

        return array_merge($steps, ['additional_media', 'review']);
    }

    public function combinations(array $data): array
    {
        if (empty($data['attribute_ids'])) {
            return [];
        }$result = [''];
        foreach ($data['attribute_ids'] as $id) {
            if (empty($data['choice_'.$id])) {
                return [];
            }$next = [];
            foreach ($result as $base) {
                foreach ($data['choice_'.$id] as $v) {
                    $next[] = ltrim($base.'-'.str_replace(' ', '', $v), '-');
                }
            }$result = $next;
            if (count($result) > 30) {
                throw ValidationException::withMessages(['attribute_ids' => 'Use at most 30 variation combinations.']);
            }
        }

        return $result;
    }

    public function categories(Store $s, ?int $parent = null)
    {
        return Category::withoutGlobalScopes()->where('module_id', $s->module_id)->where('status', 1)->when($parent !== null, fn ($q) => $q->where('parent_id', $parent))->orderBy('name');
    }

    public function optional(string $field, Store $s): bool
    {
        return in_array($field, ['subcategory_id', 'discount', 'unit_id', 'add_ons', 'additional_media', 'attribute_ids', 'tax_ids', 'food_group_count', 'extra_details', 'tags', 'brand_id', 'organic', 'nutritions', 'allergies', 'condition_id', 'generic_name', 'basic', 'manufacturer', 'unit_value'], true) || ($field === 'image' && $s->module->module_type === 'food');
    }
}
