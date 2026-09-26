<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\CoreAdapters;

use App\CentralLogics\Helpers;
use App\Models\Category;
use App\Models\Item;
use App\Models\Store;
use App\Models\Translation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductMutationService
{
    /**
     * Create a new product for a vendor store.
     *
     * @throws ValidationException
     */
    public function createProduct(Store $store, int $vendorId, array $data): Item
    {
        $this->authorizeStoreOwnership($store, $vendorId);
        $this->validateCreationRequirements($store, $data);

        $validator = validator($data, [
            'name' => 'required|string|max:191',
            'price' => 'required|numeric|between:'.Helpers::getDecimalPlaces().',999999999999.999',
            'category_id' => 'required|integer',
            'store_category_id' => 'nullable|integer',
            'stock' => 'nullable|integer|min:0',
            'discount' => 'nullable|numeric|min:0',
            'discount_type' => 'nullable|in:percent,amount',
            'description' => 'required|string|max:1000',
            'image' => 'nullable|string',
            'media_id' => 'nullable|integer|min:1',
            'veg' => 'nullable|boolean',
            'unit_id' => 'nullable|integer|exists:units,id',
            'available_time_starts' => 'nullable|date_format:H:i:s',
            'available_time_ends' => 'nullable|date_format:H:i:s',
            'is_prescription_required' => 'nullable|boolean',
            'add_ons' => 'nullable|array',
            'add_ons.*' => ['integer', \Illuminate\Validation\Rule::exists('add_ons','id')->where('store_id',$store->id)],
            'additional_media' => 'nullable|array|max:5',
            'additional_media.*' => 'integer|min:1',
            'attributes' => 'nullable|array|max:3',
            'attributes.*' => 'integer|exists:attributes,id',
            'choice_options' => 'nullable|array|max:3',
            'choice_options.*.name' => 'required|string',
            'choice_options.*.title' => 'required|string',
            'choice_options.*.options' => 'required|array|min:1|max:10',
            'variations' => 'nullable|array|max:30',
            'variations.*.type' => 'required|string|max:191',
            'variations.*.price' => 'required|numeric|between:'.Helpers::getDecimalPlaces().',999999999999.999',
            'variations.*.stock' => 'required|integer|min:0',
            'food_variations' => 'nullable|array|max:3',
            'food_variations.*.name' => 'required|string|max:80',
            'food_variations.*.type' => 'required|in:single,multi',
            'food_variations.*.min' => 'required|integer|min:0',
            'food_variations.*.max' => 'required|integer|min:1|max:10',
            'food_variations.*.required' => 'required|in:on,off',
            'food_variations.*.values' => 'required|array|min:1|max:10',
            'food_variations.*.values.*.label' => 'required|string|max:80',
            'food_variations.*.values.*.optionPrice' => 'required|numeric|min:0',
            'brand_id' => 'nullable|integer|exists:brands,id',
            'condition_id' => 'nullable|integer|exists:common_conditions,id',
            'organic' => 'nullable|boolean',
            'basic' => 'nullable|boolean',
            'manufacturer' => 'nullable|string|max:191',
            'unit_value' => 'nullable|string|max:191',
            'generic_name' => 'nullable|string|max:191',
            'tags' => 'nullable|array|max:15',
            'tags.*' => 'required|string|max:100',
            'nutritions' => 'nullable|array|max:15',
            'nutritions.*' => 'required|string|max:100',
            'allergies' => 'nullable|array|max:15',
            'allergies.*' => 'required|string|max:100',
            'tax_ids' => 'nullable|array',
            'tax_ids.*' => 'integer',


        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();
        if (!empty($validated['image']) && $validated['image'] !== 'def.png') {
            if (!ctype_digit($validated['image'])) throw ValidationException::withMessages(['image' => ['Use an image uploaded in your WhatsApp conversation.']]);
            $validated['media_id'] = (int) $validated['image'];
        }
        $this->validateDiscount((float) $validated['price'], (float) ($validated['discount'] ?? 0), $validated['discount_type'] ?? 'percent');

        foreach($validated['food_variations']??[] as $group){
            if($store->module->module_type!=='food'||$group['min']>$group['max']||$group['max']>count($group['values'])||($group['required']==='on'&&$group['min']<1)||($group['type']==='single'&&$group['max']!==1))throw ValidationException::withMessages(['food_variations'=>['Invalid food option limits.']]);
        }
        if (!empty($validated['variations'])) {
            if ($store->module->module_type === 'food') throw ValidationException::withMessages(['variations'=>['Food uses option groups rather than inventory variations.']]);
            $parts=[''];
            if(count($validated['attributes']??[])!==count($validated['choice_options']??[])) throw ValidationException::withMessages(['variations'=>['Attribute choices are incomplete.']]);
            foreach($validated['choice_options']??[] as $i=>$choice){
                $id=$validated['attributes'][$i];
                if($choice['name']!=='choice_'.$id || $choice['title']!==DB::table('attributes')->where('id',$id)->value('name'))throw ValidationException::withMessages(['variations'=>['Invalid attribute mapping.']]);
                $next=[];foreach($parts as $base)foreach($choice['options'] as $option)$next[]=ltrim($base.'-'.str_replace(' ','',$option),'-');$parts=$next;
            }
            if(count($parts)!==count($validated['variations']) || $parts!==array_column($validated['variations'],'type')) throw ValidationException::withMessages(['variations'=>['Variation combinations do not match the choices.']]);
            foreach($validated['variations'] as $variation)$this->validateDiscount((float)$variation['price'],(float)($validated['discount']??0),$validated['discount_type']??'percent');
            $validated['stock']=array_sum(array_column($validated['variations'],'stock'));
        }
        if (!empty($validated['tax_ids'])) {
            $taxes=Helpers::getTaxSystemType()['taxVats'];
            if(count(array_intersect($validated['tax_ids'],collect($taxes)->pluck('id')->all()))!==count($validated['tax_ids'])) throw ValidationException::withMessages(['tax_ids'=>['Select only configured taxes.']]);
        }

        // Validate category belongs to store module
        $category = Category::where('id', $validated['category_id'])
            ->where(function ($query) use ($store) {
                $query->where('module_id', $store->module_id)
                    ->orWhereNull('module_id');
            })
            ->first();

        if (!$category) {
            throw ValidationException::withMessages([
                'category_id' => ['The selected category does not belong to your store module.'],
            ]);
        }

        return DB::transaction(function () use ($store, $vendorId, $validated, $category) {
            $store = Store::whereKey($store->id)->where('vendor_id', $vendorId)->lockForUpdate()->firstOrFail();
            $this->checkSubscriptionItemLimit($store);
            $item = new Item();
            $item->name = $validated['name'];
            $item->price = $validated['price'];
            $item->category_id = $category->id;
            $item->category_ids = $this->categoryPath($category, (int) $store->module_id);
            $item->store_id = $store->id;
            $item->module_id = $store->module_id;
            $item->store_category_id = $validated['store_category_id'] ?? null;
            $item->stock = $validated['stock'] ?? 0;
            $item->discount = $validated['discount'] ?? 0;
            $item->discount_type = $validated['discount_type'] ?? 'percent';
            $item->description = $validated['description'] ?? null;
            $item->image = !empty($validated['media_id'])
                ? app(ProductMedia::class)->publish($validated['media_id'], $vendorId) : 'def.png';
            $item->veg = !empty($validated['veg']) ? 1 : 0;
            if(array_key_exists('organic',$validated) && $store->module->module_type==='grocery')$item->organic=$validated['organic'];
            $item->status = 1;
            $item->variations = json_encode($validated['variations'] ?? []);
            if(array_key_exists('food_variations',$validated))$item->food_variations=json_encode($validated['food_variations']);
            $item->attributes = json_encode($validated['attributes'] ?? []);
            $item->add_ons = json_encode($store->module->module_type === 'food' ? ($validated['add_ons'] ?? []) : []);
            $item->choice_options = json_encode($validated['choice_options'] ?? []);
            $item->available_time_starts = $validated['available_time_starts'] ?? '00:00:00';
            $item->available_time_ends = $validated['available_time_ends'] ?? '23:59:59';
            if (array_key_exists('unit_id',$validated)) $item->unit_id = $validated['unit_id'];
            if (!empty($validated['additional_media'])) $item->images = array_map(fn($id)=>['img'=>app(ProductMedia::class)->publish($id,$vendorId),'storage'=>Helpers::getDisk()],$validated['additional_media']);
            $item->save();

            // The host creates these rows even when optional detail inputs are empty.
            $moduleType = $store->module->module_type;
            if (in_array($moduleType, ['grocery', 'ecommerce'], true)) {
                $details = new \App\Models\EcommerceItemDetails();
                $details->item_id = $item->id;
                $details->brand_id = $validated['brand_id'] ?? null;
                $details->save();
            } elseif ($moduleType === 'pharmacy') {
                $details = new \App\Models\PharmacyItemDetails();
                $details->item_id = $item->id;
                $details->is_basic = $validated['basic'] ?? 0;
                $details->common_condition_id=$validated['condition_id']??null;
                $details->manufacturer=$validated['manufacturer']??null;
                $details->unit_value=$validated['unit_value']??null;
                $details->is_prescription_required = $validated['is_prescription_required'] ?? 0;
                $details->save();
            }

            if (!empty($validated['tax_ids']) && addon_published_status('TaxModule')) {
                $setup=\Modules\TaxModule\Entities\SystemTaxSetup::where('is_active',1)->where('is_default',1)->where('tax_type','product_wise')->first();
                if($setup)foreach($validated['tax_ids'] as $taxId)\Modules\TaxModule\Entities\Taxable::create(['taxable_type'=>Item::class,'taxable_id'=>$item->id,'system_tax_setup_id'=>$setup->id,'tax_id'=>$taxId]);
            }
            foreach(['tags'=>[\App\Models\Tag::class,'tag'],'nutritions'=>[\App\Models\Nutrition::class,'nutrition'],'allergies'=>[\App\Models\Allergy::class,'allergy']] as $relation=>[$class,$column]){
                if(empty($validated[$relation]))continue;
                if($relation!=='tags' && !in_array($store->module->module_type,['food','grocery'],true))continue;
                $ids=[];foreach($validated[$relation] as $value)$ids[]=$class::firstOrCreate([$column=>$value])->id;$item->$relation()->sync($ids);
            }
            if(!empty($validated['generic_name']) && $store->module->module_type==='pharmacy')$item->generic()->sync([\App\Models\GenericName::firstOrCreate(['generic_name'=>$validated['generic_name']])->id]);
            // Save default translation
            Translation::updateOrCreate([
                'translationable_type' => Item::class,
                'translationable_id' => $item->id,
                'locale' => 'en',
                'key' => 'name',
            ], [
                'value' => $validated['name'],
            ]);

            if (!empty($validated['description'])) {
                Translation::updateOrCreate([
                    'translationable_type' => Item::class,
                    'translationable_id' => $item->id,
                    'locale' => 'en',
                    'key' => 'description',
                ], [
                    'value' => $validated['description'],
                ]);
            }

            $review = app(ProductReview::class)->stage($item, 'Add_new_product');
            if ($review) { $item->is_approved = 0; $item->save(); }
            $result = $item->fresh(['translations']);
            if ($review) $result->setRelation('conciergeReview', $review);
            return $result;
        });
    }

    /**
     * Update product price.
     *
     * @throws ValidationException
     */
    public function updatePrice(Item $item, int $vendorId, float $newPrice): Item
    {
        $this->authorizeItemOwnership($item, $vendorId);

        if ($newPrice < (float) Helpers::getDecimalPlaces() || $newPrice > 999999999999.999) {
            throw ValidationException::withMessages([
                'price' => ['Product price must be within the configured host price range.'],
            ]);
        }

        return DB::transaction(function () use ($item, $vendorId, $newPrice) {
            $locked = Item::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $this->authorizeItemOwnership($locked, $vendorId);
            $this->validateDiscount($newPrice, (float) $locked->discount, $locked->discount_type);
            $locked->price = $newPrice;
            if ($review = app(ProductReview::class)->stage($locked, 'Update_product_price')) {
                return $locked->fresh()->setRelation('conciergeReview', $review);
            }
            $locked->save();

            return $locked;
        });
    }

    /**
     * Update product stock quantity.
     *
     * @throws ValidationException
     */
    public function updateStock(Item $item, int $vendorId, int $newStock, ?array $variationStocks = null): Item
    {
        $this->authorizeItemOwnership($item, $vendorId);

        if ($newStock < 0) {
            throw ValidationException::withMessages([
                'stock' => ['Product stock quantity cannot be negative.'],
            ]);
        }

        return DB::transaction(function () use ($item, $vendorId, $newStock, $variationStocks) {
            $locked = Item::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $this->authorizeItemOwnership($locked, $vendorId);
            $variations = json_decode($locked->variations ?: '[]', true);
            if ($variations) {
                if ($variationStocks === null || count($variationStocks) !== count($variations)) {
                    throw ValidationException::withMessages(['stock' => ['Specify stock for every variation; changing only the total would corrupt inventory.']]);
                }
                foreach ($variations as &$variation) {
                    $stock = $variationStocks[$variation['type']] ?? null;
                    if (!is_int($stock) || $stock < 0) throw ValidationException::withMessages(['stock' => ['Each variation requires a non-negative integer stock.']]);
                    $variation['stock'] = $stock;
                }
                unset($variation);
                if (array_sum(array_column($variations, 'stock')) !== $newStock) throw ValidationException::withMessages(['stock' => ['Variation stock must equal the total.']]);
                $locked->variations = json_encode($variations, JSON_THROW_ON_ERROR);
            }
            $locked->stock = $newStock;
            $locked->save();

            return $locked;
        });
    }

    /**
     * Toggle product availability (status: 1 = active, 0 = inactive).
     */
    public function toggleAvailability(Item $item, int $vendorId, ?bool $active = null): Item
    {
        $this->authorizeItemOwnership($item, $vendorId);

        return DB::transaction(function () use ($item, $vendorId, $active) {
            $locked = Item::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $this->authorizeItemOwnership($locked, $vendorId);
            if (($active ?? !$locked->status) && !$locked->status) {
                $store = Store::whereKey($locked->store_id)->lockForUpdate()->firstOrFail();
                $this->checkSubscriptionItemLimit($store);
            }
            $locked->status = $active !== null ? ($active ? 1 : 0) : ($locked->status ? 0 : 1);
            $locked->save();

            return $locked;
        });
    }

    /**
     * Update basic product details (name, description, category, discount).
     *
     * @throws ValidationException
     */
    public function updateBasicDetails(Item $item, int $vendorId, array $data): Item
    {
        $this->authorizeItemOwnership($item, $vendorId);

        $validator = validator($data, [
            'name' => 'sometimes|required|string|max:191',
            'description' => 'sometimes|required|string|max:1000',
            'category_id' => 'nullable|integer',
            'discount' => 'nullable|numeric|min:0',
            'discount_type' => 'nullable|in:percent,amount',
            'image' => 'nullable|string',
            'media_id' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = array_filter($validator->validated(), fn ($val) => $val !== null);
        if (isset($validated['image'])) {
            if (!ctype_digit($validated['image'])) {
                throw ValidationException::withMessages(['image' => ['Use an image uploaded in your WhatsApp conversation.']]);
            }
            $validated['media_id'] = (int) $validated['image'];
        }

        if (isset($validated['category_id'])) {
            $category = Category::where('id', $validated['category_id'])
                ->where(function ($query) use ($item) {
                    $query->where('module_id', $item->module_id)
                        ->orWhereNull('module_id');
                })
                ->first();

            if (!$category) {
                throw ValidationException::withMessages([
                    'category_id' => ['The selected category does not belong to this item module.'],
                ]);
            }
        }

        return DB::transaction(function () use ($item, $vendorId, $validated) {
            $locked = Item::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $this->authorizeItemOwnership($locked, $vendorId);

            if (isset($validated['name'])) {
                $locked->name = $validated['name'];

            }

            if (isset($validated['description'])) {
                $locked->description = $validated['description'];

            }

            if (isset($validated['category_id'])) {
                $locked->category_id = $validated['category_id'];
                $locked->category_ids = $this->categoryPath(Category::findOrFail($validated['category_id']), (int) $locked->module_id);
            }

            if (isset($validated['discount'])) {
                $locked->discount = $validated['discount'];
            }
            if (isset($validated['discount_type'])) {
                $locked->discount_type = $validated['discount_type'];
            }
            if (isset($validated['media_id'])) {
                $locked->image = app(ProductMedia::class)->publish($validated['media_id'], $vendorId);
            }

            $this->validateDiscount((float) $locked->price, (float) $locked->discount, $locked->discount_type);
            if ($review = app(ProductReview::class)->stage($locked, 'Update_anything_in_product_details')) {
                return $locked->fresh(['translations'])->setRelation('conciergeReview', $review);
            }
            $locked->save();
            foreach (['name', 'description'] as $field) {
                if (isset($validated[$field])) Translation::updateOrCreate([
                    'translationable_type' => Item::class,
                    'translationable_id' => $locked->id,
                    'locale' => 'en', 'key' => $field,
                ], ['value' => $validated[$field]]);
            }

            return $locked->fresh(['translations']);
        });
    }

    public function validateCreationRequirements(Store $store, array $data): void
    {
        // Match the host's conditional store-category rule without memoizing a
        // per-request result for the lifetime of a queue worker.
        $needsStoreCategory = Helpers::storeCategoryStatus()
            && \App\Models\StoreCategory::where('store_id', $store->id)->exists();
        validator($data, [
            'name' => 'required|string|max:191',
            'price' => 'required|numeric|between:'.Helpers::getDecimalPlaces().',999999999999.999',
            'description' => 'required|string|max:1000',
            'store_category_id' => [
                $needsStoreCategory ? 'required' : 'nullable', 'integer',
                \Illuminate\Validation\Rule::exists('store_categories', 'id')->where('store_id', $store->id),
            ],
        ])->validate();
        $mediaId = $data['media_id'] ?? (ctype_digit((string) ($data['image'] ?? '')) ? (int) $data['image'] : null);
        if ($store->module->module_type !== 'food' && !$mediaId) {
            throw ValidationException::withMessages(['image' => ['Upload a product photo before creating this listing.']]);
        }
        if ($mediaId) app(ProductMedia::class)->owned((int) $mediaId, (int) $store->vendor_id);
    }

    private function categoryPath(Category $category, int $moduleId): string
    {
        $ids = [];
        while ($category) {
            if (isset($ids[$category->id]) || ($category->module_id !== null && (int) $category->module_id !== $moduleId)) {
                throw ValidationException::withMessages(['category_id' => ['The category hierarchy is invalid for this module.']]);
            }
            $ids[$category->id] = (string) $category->id;
            if (!$category->parent_id) break;
            $category = Category::find($category->parent_id);
            if (!$category) throw ValidationException::withMessages(['category_id' => ['The parent category is unavailable.']]);
        }
        $path = [];
        foreach (array_reverse(array_values($ids)) as $index => $id) $path[] = ['id' => $id, 'position' => $index + 1];
        return json_encode($path, JSON_THROW_ON_ERROR);
    }

    private function authorizeStoreOwnership(Store $store, int $vendorId): void
    {
        if ((int) $store->vendor_id !== $vendorId) {
            abort(403, 'Unauthorized store access.');
        }
    }

    private function authorizeItemOwnership(Item $item, int $vendorId): void
    {
        $store = $item->store ?? Store::find($item->store_id);
        if (!$store || (int) $store->vendor_id !== $vendorId) {
            abort(403, 'Unauthorized product access.');
        }
    }

    private function checkSubscriptionItemLimit(Store $store): void
    {
        if ($store->getRawOriginal('item_section') !== null && !$store->item_section) {
            throw ValidationException::withMessages(['subscription' => ['Product management is disabled for this store.']]);
        }
        if ($store->store_business_model === 'unsubscribed') {
            throw ValidationException::withMessages(['subscription' => ['An active subscription is required.']]);
        }
        if ($store->store_business_model === 'subscription') {
            $subscription = $store->store_sub;
            if (!$subscription) throw ValidationException::withMessages(['subscription' => ['An active subscription is required.']]);
            if ($subscription->max_product !== 'unlimited' && Item::withoutGlobalScopes()->where('store_id', $store->id)->where('status', 1)->count() >= (int) $subscription->max_product) {
                throw ValidationException::withMessages(['subscription' => ['The subscription product limit has been reached.']]);
            }
        }
    }

    private function validateDiscount(float $price, float $discount, string $type): void
    {
        $amount = $type === 'percent' ? $price * $discount / 100 : $discount;
        if ($amount > 0 && $amount >= $price) throw ValidationException::withMessages(['discount' => ['Discount must be less than the product price.']]);
    }
}
