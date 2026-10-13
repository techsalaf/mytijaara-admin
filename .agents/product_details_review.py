from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/app/Services/CoreAdapters/ProductMutationService.php')
s=p.read_text(encoding='utf-8')
start=s.index('        return DB::transaction(function () use ($item, $validated)')
end=s.index('    private function authorizeStoreOwnership',start)
chunk=s[start:end].replace('use ($item, $validated)', 'use ($item, $vendorId, $validated)')
chunk=chunk.replace('$locked = Item::whereKey($item->id)->lockForUpdate()->firstOrFail();', '$locked = Item::whereKey($item->id)->lockForUpdate()->firstOrFail();\n            $this->authorizeItemOwnership($locked, $vendorId);')
for field in ['name','description']:
 a=chunk.index('                Translation::updateOrCreate(')
 b=chunk.index("['value' => $validated['"+field+"']]);",a)+len("['value' => $validated['"+field+"']]);")
 chunk=chunk[:a]+chunk[b:]
chunk=chunk.replace('            $locked->save();', '''            $this->validateDiscount((float) $locked->price, (float) $locked->discount, $locked->discount_type);
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
            }''')
p.write_text(s[:start]+chunk+s[end:],encoding='utf-8')
