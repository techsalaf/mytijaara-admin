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

        $validator = validator($data, [
            'name' => 'required|string|max:191',
            'price' => 'required|numeric|min:0.01',
            'category_id' => 'required|integer',
            'stock' => 'nullable|integer|min:0',
            'discount' => 'nullable|numeric|min:0',
            'discount_type' => 'nullable|in:percent,amount',
            'description' => 'nullable|string',
            'image' => 'nullable|string',
            'media_id' => 'nullable|integer|min:1',
            'veg' => 'nullable|boolean',
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
            $item->category_ids = json_encode([['id' => (string) $category->id, 'position' => 1]]);
            $item->store_id = $store->id;
            $item->module_id = $store->module_id;
            $item->stock = $validated['stock'] ?? 0;
            $item->discount = $validated['discount'] ?? 0;
            $item->discount_type = $validated['discount_type'] ?? 'percent';
            $item->description = $validated['description'] ?? null;
            $item->image = !empty($validated['media_id'])
                ? app(ProductMedia::class)->publish($validated['media_id'], $vendorId) : 'def.png';
            $item->veg = !empty($validated['veg']) ? 1 : 0;
            $item->status = 1;
            $item->variations = json_encode([]);
            $item->attributes = json_encode([]);
            $item->add_ons = json_encode([]);
            $item->choice_options = json_encode([]);
            $item->available_time_starts = '00:00:00';
            $item->available_time_ends = '23:59:59';
            $item->save();

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

        if ($newPrice <= 0) {
            throw ValidationException::withMessages([
                'price' => ['Product price must be greater than zero.'],
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
            'name' => 'nullable|string|max:191',
            'description' => 'nullable|string',
            'category_id' => 'nullable|integer',
            'discount' => 'nullable|numeric|min:0',
            'discount_type' => 'nullable|in:percent,amount',
            'image' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = array_filter($validator->validated(), fn ($val) => $val !== null);

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
                $locked->category_ids = json_encode([['id' => (string) $validated['category_id'], 'position' => 1]]);
            }

            if (isset($validated['discount'])) {
                $locked->discount = $validated['discount'];
            }
            if (isset($validated['discount_type'])) {
                $locked->discount_type = $validated['discount_type'];
            }
            if (isset($validated['image'])) {
                $locked->image = $validated['image'];
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
