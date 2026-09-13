<?php

namespace App\Services;

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
        $this->checkSubscriptionItemLimit($store);

        $validator = validator($data, [
            'name' => 'required|string|max:191',
            'price' => 'required|numeric|min:0.01',
            'category_id' => 'required|integer',
            'stock' => 'nullable|integer|min:0',
            'discount' => 'nullable|numeric|min:0',
            'discount_type' => 'nullable|in:percent,amount',
            'description' => 'nullable|string',
            'image' => 'nullable|string',
            'veg' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();

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

        return DB::transaction(function () use ($store, $validated, $category) {
            $item = new Item();
            $item->name = $validated['name'];
            $item->price = $validated['price'];
            $item->category_id = $category->id;
            $item->category_ids = json_encode([['id' => (string) $category->id, 'position' => 1]]);
            $item->store_id = $store->id;
            $item->module_id = $store->module_id;
            $item->stock = $validated['stock'] ?? 10;
            $item->discount = $validated['discount'] ?? 0;
            $item->discount_type = $validated['discount_type'] ?? 'percent';
            $item->description = $validated['description'] ?? null;
            $item->image = $validated['image'] ?? 'def.png';
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

            return $item->fresh(['translations']);
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

        return DB::transaction(function () use ($item, $newPrice) {
            $locked = Item::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $locked->price = $newPrice;
            $locked->save();

            return $locked;
        });
    }

    /**
     * Update product stock quantity.
     *
     * @throws ValidationException
     */
    public function updateStock(Item $item, int $vendorId, int $newStock): Item
    {
        $this->authorizeItemOwnership($item, $vendorId);

        if ($newStock < 0) {
            throw ValidationException::withMessages([
                'stock' => ['Product stock quantity cannot be negative.'],
            ]);
        }

        return DB::transaction(function () use ($item, $newStock) {
            $locked = Item::whereKey($item->id)->lockForUpdate()->firstOrFail();
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

        return DB::transaction(function () use ($item, $active) {
            $locked = Item::whereKey($item->id)->lockForUpdate()->firstOrFail();
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

        return DB::transaction(function () use ($item, $validated) {
            $locked = Item::whereKey($item->id)->lockForUpdate()->firstOrFail();

            if (isset($validated['name'])) {
                $locked->name = $validated['name'];
                Translation::updateOrCreate([
                    'translationable_type' => Item::class,
                    'translationable_id' => $locked->id,
                    'locale' => 'en',
                    'key' => 'name',
                ], ['value' => $validated['name']]);
            }

            if (isset($validated['description'])) {
                $locked->description = $validated['description'];
                Translation::updateOrCreate([
                    'translationable_type' => Item::class,
                    'translationable_id' => $locked->id,
                    'locale' => 'en',
                    'key' => 'description',
                ], ['value' => $validated['description']]);
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

            $locked->save();

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
        if (in_array($store->store_business_model, ['subscription', 'unsubscribed'])) {
            $subscription = $store->store_sub ?? null;
            if ($subscription && $subscription->package) {
                $maxItems = $subscription->package->max_item ?? 0;
                if ($maxItems > 0) {
                    $currentCount = Item::withoutGlobalScopes()->where('store_id', $store->id)->count();
                    if ($currentCount >= $maxItems) {
                        throw ValidationException::withMessages([
                            'subscription' => ['You have reached the maximum product limit allowed in your subscription package.'],
                        ]);
                    }
                }
            }
        }
    }
}
