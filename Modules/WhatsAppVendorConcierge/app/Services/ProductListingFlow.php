<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use App\CentralLogics\Helpers;
use App\Models\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\WhatsAppVendorConcierge\app\Jobs\ProcessWhatsAppMedia;
use Modules\WhatsAppVendorConcierge\app\Models\ProductListingDraft as Draft;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMedia;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Services\CoreAdapters\ProductMedia;
use Modules\WhatsAppVendorConcierge\app\Services\CoreAdapters\ProductMutationService;

/** Conversation drafts only; final products are owned by the canonical item adapter. */
class ProductListingFlow
{
    public function __construct(private ProductFieldMap $fields) {}

    public function sendReply(WhatsAppGateway $gateway, string $phone, string $reply): void
    {
        while ($reply !== '') {
            $chunk = mb_substr($reply, 0, 3500);
            $reply = mb_substr($reply, 3500);
            $gateway->sendTextMessage($phone, $chunk);
        }
    }

    public function current(WhatsAppConversation $c): ?Draft
    {
        return Draft::where('conversation_id', $c->id)->whereIn('status', ['active', 'saved'])->latest('id')->first();
    }

    public function start(WhatsAppConversation $c, WhatsAppContact $contact): Draft
    {
        abort_unless($c->contact_id === $contact->id && $contact->vendor_id, 403);
        $s = Store::where('vendor_id', $contact->vendor_id)->firstOrFail();
        abort_unless((int) $s->vendor->status === 1 && $s->status, 403, 'Only approved stores can list products.');
        abort_unless(in_array($s->module->module_type, ProductFieldMap::TYPES, true), 422, 'Use the module dashboard for this listing type.');

        return Cache::lock('product-draft-'.$c->id, 60)->block(5, function () use ($c, $contact, $s) {
            if ($d = $this->current($c)) {
                return $d;
            }
            $legacy = $c->context['photo_to_product_draft'] ?? [];
            if (empty($legacy['media_id']) && ! empty($c->context['last_product_media_id'])) {
                $legacy['media_id'] = $c->context['last_product_media_id'];
            }
            if (($legacy['stock'] ?? null) === 0) {
                unset($legacy['stock']);
            } // Legacy flow silently defaulted this; ask the vendor.
            $data = [];
            $sources = [];
            foreach (['name', 'description', 'price', 'stock', 'category_id', 'media_id'] as $f) {
                if (isset($legacy[$f])) {
                    $data[$f] = $legacy[$f];
                    $sources[$f] = 'vendor (recovered)';
                }
            }
            if (! empty($data['media_id'])) {
                $data['image'] = $data['media_id'];
            }
            $d = Draft::create(['conversation_id' => $c->id, 'contact_id' => $contact->id, 'store_id' => $s->id, 'module_id' => $s->module_id, 'data' => $data, 'sources' => $sources]);
            $d->step = $this->next($d);
            $d->save();
            $ctx = $c->context ?? [];
            unset($ctx['photo_to_product_draft'],$ctx['last_product_media_id']);
            $c->update(['context' => $ctx]);

            return $d;
        });
    }

    public function handles(WhatsAppConversation $c, string $text): bool
    {
        $d = $this->current($c);

        return ($d && ($d->status === 'active' || in_array(strtolower(trim($text)), ['resume', 'resume product', 'continue product'], true))) || ! empty($c->context['last_product_media_id']) || in_array(strtolower(trim($text)), ['add product', 'add new product', 'resume product', 'confirm', 'confirm and create'], true);
    }

    public function receiveOrDefer(WhatsAppConversation $c,WhatsAppContact $contact,WhatsAppMessage $message): string
    {
        try {return $this->receive($c,$contact,$message);}
        catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            $draft=$this->current($c);
            if(!$draft || !$message->exists)throw $e;
            \Modules\WhatsAppVendorConcierge\app\Jobs\ResumeProductDraftMessage::dispatch($draft->id,$message->id,$draft->step)
                ->onConnection(config('whatsapp-vendor-concierge.queue.connection','database')==='sync'?'database':config('whatsapp-vendor-concierge.queue.connection','database'))
                ->onQueue(config('whatsapp-vendor-concierge.queue.jobs.process_incoming','whatsapp.process_incoming'))->delay(now()->addSeconds(5));
            return 'Your image or previous answer is still being processed. Your new reply is saved and will continue automatically.';
        }
    }

    public function receive(WhatsAppConversation $c, WhatsAppContact $contact, WhatsAppMessage $m): string
    {
        $d = $this->current($c);
        if (! $d && in_array(strtolower(trim((string) $m->raw_text)), ['confirm', 'confirm and create'], true)) {
            $completed = Draft::where('conversation_id', $c->id)->whereNotNull('item_id')->latest('id')->first();

            return $completed ? 'Already created: product #'.$completed->item_id : 'No product is awaiting confirmation. Choose Add Products.';
        }
        $d ??= $this->start($c, $contact);

        return Cache::lock('product-draft-'.$c->id, 90)->block(5, function () use ($d, $contact, $m) {
            $d->refresh();
            abort_unless($d->contact_id === $contact->id && $d->store->vendor_id === $contact->vendor_id, 403);
            $seen = $d->processed_messages ?? [];
            if ($m->id && in_array($m->id, $seen, true)) {
                return '';
            }
            if ($m->id) {
                $seen[] = $m->id;
                $d->processed_messages = array_slice($seen, -100);
            }
            $text = trim((string) $m->raw_text);
            $cmd = strtolower($text);
            $before = $d->data;
            $oldStep = $d->step;
            if (in_array($cmd, ['cancel', 'cancel product'], true)) {
                $d->status = 'cancelled';
                $d->save();

                return 'Product draft cancelled. No product was created.';
            }
            if (in_array($cmd, ['save', 'save as draft', 'save draft', 'menu'], true)) {
                $d->status = 'saved';
                $d->save();

                return 'Draft saved. You can use the concierge for other tasks. Reply Resume product to continue.';
            }
            if (in_array($cmd, ['resume', 'resume product', 'continue product', 'add product', 'add new product'], true)) {
                $d->status = 'active';
                $d->save();

                return $this->prompt($d);
            }
            if ($cmd === 'continue without ai') {
                $d->vision = ['status' => 'disabled_by_vendor'];
            }
            if (in_array($cmd, ['help', 'explain', 'examples', 'try again', 'continue without ai'], true)) {
                $d->stalled_turns = 0;
                $d->save();

                return $this->prompt($d)."\nUse Back, Edit name/category/description/price/stock/image, Save draft, Cancel, or Support. Only optional questions allow Skip.";
            }
            if (str_starts_with($cmd, 'options')) {
                return $this->options($d, trim(substr($text, 7)));
            }
            if ($cmd === 'back' || str_starts_with($cmd, 'edit ')) {
                $steps = $this->fields->steps($d->store, $d->data);
                $f = $cmd === 'back' ? $steps[max(0, array_search($d->step, $steps, true) - 1)] : substr($cmd, 5);
                $f = ['category' => 'category_id', 'subcategory' => 'subcategory_id', 'unit' => 'unit_id', 'image' => 'image'][$f] ?? $f;
                if (! in_array($f, $steps, true)) {
                    return 'That field is not available for this shop. '.$this->prompt($d);
                }
                $d->step = $f;
                $d->save();

                return $this->prompt($d);
            }
            if (($cmd === 'confirm' || $cmd === 'confirm and create') && $d->status === 'saved') {
                return 'Your draft is saved. Reply Resume product, review it, then confirm.';
            }
            if ($cmd === 'confirm' || $cmd === 'confirm and create') {
                if ($d->step !== 'review') {
                    return 'Please finish the current question first. '.$this->prompt($d);
                }
                try {
                    return $this->create($d, $contact);
                } catch (ValidationException $e) {
                    $d->errors = $e->errors();
                    $d->needs_attention = true;
                    $d->save();

                    return 'Nothing was created: '.implode(' ', array_merge(...array_values($e->errors()))).' Use Edit followed by the field name, or Support.';
                }
            }
            try {
                if ($m->type === 'image') {
                    $pending = WhatsAppMedia::find($m->media_id);
                    if ($pending && $pending->status === 'pending_download') {
                        Cache::lock('product-media-'.$pending->id, 45)->block(5, fn () => ProcessWhatsAppMedia::dispatchSync($pending));
                    }
                    if (! $pending) {
                        throw ValidationException::withMessages(['image' => 'The image reference is missing. Please upload it again.']);
                    }
                    $media = app(ProductMedia::class)->owned((int) $m->media_id, (int) $contact->vendor_id);
                    $check = app(MediaPolicyService::class)->validateProductImage($media);
                    if (! $check['valid']) {
                        throw ValidationException::withMessages(['image' => $check['error']]);
                    }
                    $data = $d->data;
                    $sources = $d->sources;
                    if ($d->step === 'additional_media') {
                        $data['additional_media'] = array_values(array_unique(array_merge($data['additional_media'] ?? [], [$media->id])));
                        if (count($data['additional_media']) > 5) {
                            throw ValidationException::withMessages(['image' => 'Use up to five additional photos.']);
                        }
                    } else {
                        $data['media_id'] = $media->id;
                        $data['image'] = $media->id;
                        $sources['image'] = 'vendor';
                        // Save the owned image and next step before the external request.
                        $disabled=in_array($d->vision['status']??'', ['disabled_by_vendor','disabled_by_admin'], true);
                        $d->data=$data;$d->sources=$sources;$d->step=$this->next($d);
                        if(!$disabled)$d->vision=['status'=>'processing'];
                        $d->save();
                        if(!$disabled)$d->vision=app(ProductVisionService::class)->analyse($media,$d->store);
                    }
                    $d->data = $data;
                    $d->sources = $sources;
                } elseif ($cmd === 'skip') {
                    if (! $this->fields->optional($d->step, $d->store)) {
                        throw ValidationException::withMessages([$d->step => 'This question is required.']);
                    }
                    $data = $d->data;
                    $data[$d->step] = $d->step === 'additional_media' ? ($data['additional_media'] ?? []) : match ($d->step) {
                        'discount','food_group_count','extra_details','organic','basic' => 0,'additional_media','add_ons','attribute_ids','tax_ids','tags','nutritions','allergies' => [],default => null
                    };
                    $d->data = $data;
                    if ($d->step === 'image') {
                        $data = $d->data;
                        unset($data['media_id']);
                        $d->data = $data;
                    }
                    $sources = $d->sources;
                    $sources[$d->step] = 'canonical default / optional omitted';
                    $d->sources = $sources;
                } elseif ($d->step === 'review') {
                    return $this->prompt($d);
                } else {
                    // Preserve explicit labelled facts without guessing operational values.
                    $values = [];
                    foreach (['name', 'description', 'price', 'stock'] as $f) {
                        if (preg_match('/(?:^|\n)\s*'.$f.'\s*:\s*([^\n]+)/iu', $text, $a)) {
                            $values[$f] = trim($a[1]);
                        }
                    }
                    if ($d->step === 'name' && preg_match('/^(?:this is|it is)\s+(.+?),?\s+(?:and\s+)?I sell it for\s*(?:₦|NGN)?\s*([\d,.]+)\.?$/iu', $text, $a)) {
                        $values['name'] = rtrim($a[1], ', ');
                        $values['price'] = $a[2];
                    }
                    if (preg_match('/(?:^|\n)\s*category\s*:\s*([^\n]+)/iu', $text, $a)) {
                        $values['category_id'] = trim($a[1]);
                    }
                    if (! $values) {
                        $values[$d->step] = $text;
                    }
                    foreach ($values as $f => $value) {
                        $this->answer($d, $f, $value);
                    }
                }
                $d->errors = [];
                $d->stalled_turns = 0;
                $d->needs_attention = false;
                if ($d->step !== 'additional_media' || $m->type !== 'image') {
                    $d->step = $this->next($d);
                }
            } catch (ValidationException $e) {
                $d->errors = $e->errors();
                $d->stalled_turns++;
                if (! array_key_exists($oldStep, $before) && array_key_exists($oldStep, $d->data)) {
                    $d->step = $this->next($d);
                }
            }
            if ($before === $d->data && $oldStep === $d->step && ! $d->errors) {
                $d->stalled_turns++;
            }
            if ($d->stalled_turns >= 3) {
                $d->needs_attention = true;
            }
            $variants = $this->fields->combinations($d->data);
            if ($variants && count(array_filter(array_keys($d->data), fn ($key) => str_starts_with($key, 'variant_stock_'))) === count($variants)) {
                $values = $d->data;
                $values['stock'] = array_sum(array_map(fn ($i) => $values['variant_stock_'.$i], array_keys($variants)));
                $d->data = $values;
                $sources = $d->sources;
                $sources['stock'] = 'sum of vendor variation quantities';
                $d->sources = $sources;
            }
            $d->save();
            if ($d->needs_attention) {
                return 'Your draft is safe, but this step needs attention. Try Examples, Back, Edit category, Continue without AI, Save draft, or Support. An admin attention flag has been added.';
            }
            $prefix = $d->errors ? implode(' ', array_merge(...array_values($d->errors)))."\n" : '';
            if ($m->type === 'image' && $oldStep !== 'additional_media') {
                $prefix .= ($d->vision['status'] ?? '') === 'success' ? "Image checked. Suggestions below need your confirmation.\n" : "Image saved. AI image analysis is unavailable, so we will continue step by step.\n";
            }

            return $prefix.$this->prompt($d);
        });
    }

    public function next(Draft $d): string
    {
        foreach ($this->fields->steps($d->store, $d->data) as $f) {
            if ($f === 'review') {
                return $f;
            }
            if ($f === 'subcategory_id' && ! empty($d->data['category_id']) && ! $this->fields->categories($d->store, (int) $d->data['category_id'])->exists()) {
                continue;
            }
            if (! array_key_exists($f, $d->data)) {
                return $f;
            }
        }

return 'review';
    }

    public function answer(Draft $d, string $f, mixed $v): void
    {
        $data = $d->data;
        $sources = $d->sources;
        $type = $d->store->module->module_type;
        if (! in_array($f, $this->fields->steps($d->store, $d->data), true)) {
            return;
        }
        if (in_array($f, ['tags', 'nutritions', 'allergies'], true)) {
            $v = array_values(array_unique(array_map('trim', explode(',', (string) $v))));
            validator(['values' => $v], ['values' => 'array|max:15', 'values.*' => 'required|string|max:100'])->validate();
        } elseif (in_array($f, ['extra_details', 'organic', 'basic'], true)) {
            $v = match (strtolower((string) $v)) {
                'yes','1' => 1,'no','0' => 0,default => null
            };
            if ($v === null) {
                throw ValidationException::withMessages([$f => 'Reply Yes or No, or Skip.']);
            }
        } elseif (in_array($f, ['manufacturer', 'unit_value', 'generic_name'], true)) {
            validator(['value' => $v], ['value' => 'required|string|max:191'])->validate();
        } elseif (in_array($f, ['brand_id', 'condition_id'], true)) {
            $table = $f === 'brand_id' ? 'brands' : 'common_conditions';
            $row = DB::table($table)->where('id', ctype_digit((string) $v) ? (int) $v : -1)->first();
            if (! $row) {
                throw ValidationException::withMessages([$f => 'Choose an existing ID, or Skip.']);
            }$v = $row->id;
        } elseif ($f === 'food_group_count') {
            validator(['count' => $v], ['count' => 'required|integer|between:0,3'])->validate();
            $v = (int) $v;
            foreach (array_keys($data) as $key) {
                if (preg_match('/^food_\d+_/', $key)) {
                    unset($data[$key]);
                }
            }
        } elseif (preg_match('/^food_(\d+)_(name|required|min|max|options|price_\d+)$/', $f, $a)) {
            $part = $a[2];
            if ($part === 'name') {
                validator(['name' => $v], ['name' => 'required|string|max:80'])->validate();
            } elseif ($part === 'required') {
                $v = match (strtolower((string) $v)) {
                    'yes','1' => 'on','no','0' => 'off',default => null
                };
                if ($v === null) {
                    throw ValidationException::withMessages([$f => 'Reply Yes or No.']);
                }
            } elseif ($part === 'options') {
                $v = array_values(array_unique(array_map('trim', explode(',', (string) $v))));
                validator(['options' => $v], ['options' => 'required|array|min:1|max:10', 'options.*' => 'required|string|max:80'])->validate();
            } else {
                validator(['value' => $v], ['value' => str_starts_with($part, 'price_') ? 'required|numeric|min:0|max:999999999999' : 'required|integer|between:0,10'])->validate();
                $v += 0;
            }
        } elseif ($f === 'attribute_ids') {
            $v = array_values(array_unique(array_map('intval', explode(',', (string) $v))));
            if (count($v) > 3 || DB::table('attributes')->whereIn('id', $v)->count() !== count($v)) {
                throw ValidationException::withMessages([$f => 'Choose up to three existing attribute IDs, or Skip.']);
            }
            foreach (array_keys($data) as $key) {
                if (str_starts_with($key, 'choice_') || str_starts_with($key, 'variant_')) {
                    unset($data[$key]);
                }
            }
        } elseif (str_starts_with($f, 'choice_')) {
            $v = array_values(array_unique(array_map('trim', explode(',', (string) $v))));
            if (count($v) > 10 || count($v) < 1) {
                throw ValidationException::withMessages([$f => 'Use 1–10 comma-separated options.']);
            }
            foreach ($v as $value) {
                validator(['option' => $value], ['option' => 'required|string|max:40|regex:/^[\\pL\\pN ]+$/u'])->validate();
            }
            foreach (array_keys($data) as $key) {
                if (str_starts_with($key, 'variant_')) {
                    unset($data[$key]);
                }
            }
        } elseif (str_starts_with($f, 'variant_price_') || str_starts_with($f, 'variant_stock_')) {
            $v = str_replace([',', '₦', ' '], '', (string) $v);
            validator(['value' => $v], ['value' => str_starts_with($f, 'variant_stock_') ? 'required|integer|min:0' : 'required|numeric|between:'.Helpers::getDecimalPlaces().',999999999999.999'])->validate();
            $v += 0;
        } elseif ($f === 'tax_ids') {
            $v = array_values(array_unique(array_map('intval', explode(',', (string) $v))));
            $taxes = Helpers::getTaxSystemType()['taxVats'];
            if (count(array_intersect($v, collect($taxes)->pluck('id')->all())) !== count($v)) {
                throw ValidationException::withMessages([$f => 'Select only the configured tax IDs, or Skip.']);
            }
        } elseif (in_array($f, ['category_id', 'subcategory_id', 'store_category_id', 'unit_id'], true)) {
            $q = match ($f) {
                'category_id' => $this->fields->categories($d->store, 0),'subcategory_id' => $this->fields->categories($d->store, (int) ($data['category_id'] ?? 0)),'store_category_id' => DB::table('store_categories')->where('store_id', $d->store_id),'unit_id' => DB::table('units')
            };
            $rows = (clone $q)->where(fn ($q) => $q->where('id', ctype_digit((string) $v) ? (int) $v : -1)->orWhere($f === 'unit_id' ? 'unit' : 'name', $v))->get();
            if ($rows->count() !== 1) {
                throw ValidationException::withMessages([$f => 'No exact matching option. Choose a listed ID or search using Options followed by a word.'.(in_array($f, ['category_id', 'subcategory_id'], true) ? "\nPossible options (please choose an ID):\n".$this->options($d, (string) $v) : '')]);
            }
            $v = $rows->first()->id;
            if ($f === 'category_id') {
                unset($data['subcategory_id']);
            }
        } elseif (in_array($f, ['price', 'stock', 'discount'], true)) {
            $v = str_replace([',', '₦', 'NGN', ' '], '', (string) $v);
            validator([$f => $v], [$f => $f === 'stock' ? 'required|integer|min:0' : ($f === 'price' ? 'required|numeric|between:'.Helpers::getDecimalPlaces().',999999999999.999' : 'required|numeric|min:0|max:100')])->validate();
            $v += 0;
            if ($f === 'discount' && $v >= 100) {
                throw ValidationException::withMessages(['discount' => 'Discount must be less than 100%.']);
            }
        } elseif (in_array($f, ['veg', 'is_prescription_required'], true)) {
            $v = match (strtolower((string) $v)) {
                'yes','1','vegetarian' => 1,'no','0','non-vegetarian' => 0,default => null
            };
            if ($v === null) {
                throw ValidationException::withMessages([$f => 'Reply Yes or No.']);
            }
        } elseif (in_array($f, ['available_time_starts', 'available_time_ends'], true)) {
            validator(['time' => $v], ['time' => 'required|date_format:H:i'])->validate();
            $v .= ':00';
        } elseif ($f === 'add_ons') {
            $v = array_map('intval', explode(',', (string) $v));
            if (DB::table('add_ons')->where('store_id', $d->store_id)->whereIn('id', $v)->count() !== count(array_unique($v))) {
                throw ValidationException::withMessages([$f => 'Choose only your store add-on IDs, or Skip.']);
            }
        } elseif (in_array($f, ['name', 'description'], true)) {
            validator([$f => $v], [$f => 'required|string|max:'.($f === 'name' ? 191 : 1000)])->validate();
        } else {
            throw ValidationException::withMessages([$f => 'Please send a photo, or Skip if this field is optional.']);
        }
        $data[$f] = $v;
        if (str_starts_with($f, 'choice_')) {
            $this->fields->combinations($data);
        }
        $sources[$f] = isset($d->vision['suggestions'][$f]) && (string) $d->vision['suggestions'][$f] === (string) $v ? 'AI suggestion confirmed by vendor' : 'vendor';
        $d->data = $data;
        $d->sources = $sources;
    }

    public function prompt(Draft $d): string
    {
        $f = $d->step;
        $s = $d->store;
        if ($f === 'review') {
            $lines = ['Review your product (nothing created yet):'];
            foreach ($d->data as $k => $v) {
                $display = is_array($v) ? json_encode($v) : ($v === null ? 'not provided' : (string) $v);
                if (in_array($k, ['category_id', 'subcategory_id'], true)) {
                    $display = $this->fields->categories($s)->whereKey($v)->value('name') ?: $display;
                }if (preg_match('/^variant_(price|stock)_(\d+)$/', $k, $a)) {
                    $label = ($this->fields->combinations($d->data)[(int) $a[2]] ?? 'Variation').' '.$a[1];
                } else {
                    $label = $k === 'discount' ? 'Discount (%)' : str_replace('_', ' ', ucfirst($k));
                }$lines[] = $label.': '.$display.' ['.($d->sources[$k] ?? 'vendor').']';
            }

            return implode("\n", $lines)."\nReply Confirm and create, Edit followed by a field name, Back, Save draft, or Cancel. Publishing follows admin approval settings.";
        }
        if ($f === 'extra_details') {
            return 'Would you like to add optional details such as brand, tags or module-specific information? Yes or Skip.';
        }
        if (in_array($f, ['brand_id', 'condition_id'], true)) {
            return 'Choose an existing '.($f === 'brand_id' ? 'brand' : 'condition').' ID, or Skip: '.DB::table($f === 'brand_id' ? 'brands' : 'common_conditions')->limit(20)->get()->map(fn ($r) => $r->id.': '.$r->name)->implode(', ');
        }
        if (in_array($f, ['organic', 'basic'], true)) {
            return ($f === 'organic' ? 'Is this product organic?' : 'Is this a basic pharmacy item?').' Only confirm verified information. Yes / No / Skip.';
        }
        if (in_array($f, ['tags', 'nutritions', 'allergies', 'manufacturer', 'unit_value', 'generic_name'], true)) {
            return 'Provide '.str_replace('_', ' ', $f).(in_array($f, ['tags', 'nutritions', 'allergies'], true) ? ', separated by commas' : '').'. Use only information you can verify, or Skip.';
        }
        if ($f === 'food_group_count') {
            return 'How many option groups does this food have (0–3)? Example: size, protein or toppings. Send 0 or Skip for none.';
        }
        if (preg_match('/^food_(\d+)_(name|required|min|max|options|price_(\d+))$/', $f, $a)) {
            return 'Food option group '.((int) $a[1] + 1).': '.match ($a[2]) {
                'name' => 'What is the group name?','required' => 'Must a customer choose an option? Yes or No.','min' => 'Minimum number of choices? Use 0 for optional.','max' => 'Maximum number of choices? Use 1 for a single choice.','options' => 'List the option names separated by commas.',default => 'Extra price in naira for '.($d->data['food_'.$a[1].'_options'][(int) ($a[3] ?? 0)] ?? 'this option').'? Use 0 for no extra charge.'
            };
        }
        if (str_starts_with($f, 'choice_')) {
            return 'List options for '.DB::table('attributes')->where('id', substr($f, 7))->value('name').', separated by commas. Example: Black, Blue. Back / Save draft / Cancel.';
        }
        if (preg_match('/^variant_(price|stock)_(\d+)$/', $f, $a)) {
            return 'For variation '.($this->fields->combinations($d->data)[(int) $a[2]] ?? '').', what is the '.($a[1] === 'price' ? 'actual price in naira?' : 'stock quantity?').' Back / Save draft / Cancel.';
        }
        $prompt = match ($f) {
            'attribute_ids' => 'Does it have variants such as size or colour? Choose attribute IDs, separated by commas, or Skip.','tax_ids' => 'Choose existing tax IDs separated by commas, or Skip. Only your admin-configured taxes are available.','image' => 'Send a clear product photo. Image suggestions depend on available AI; you always set the price and stock.','name' => 'What is the product name?','category_id' => 'Choose a category ID for your shop module:','subcategory_id' => 'Choose a subcategory ID, or Skip:','description' => 'Describe this product (up to 1,000 characters).','unit_id' => 'Choose its selling unit ID, or Skip:','store_category_id' => 'Choose one of your store category IDs:','price' => 'What is the actual selling price in naira? Example: 1800','discount' => 'What percentage discount do you offer? Send 0 or Skip for none.','stock' => 'How many units are available? Send a whole number, including 0.','veg' => 'Is this food vegetarian? Reply Yes or No.','is_prescription_required' => 'Does this item require a prescription? Reply Yes or No. Do not infer this from its photo.','available_time_starts' => 'What time does daily availability start? Use 24-hour HH:MM.','available_time_ends' => 'What time does daily availability end? Use 24-hour HH:MM.','add_ons' => 'Send existing add-on IDs separated by commas, or Skip.','additional_media' => 'Send up to five extra product photos, one at a time. Reply Skip when finished.',default => 'Reply with the requested detail.'
        };
        if (in_array($f, ['category_id', 'subcategory_id'], true)) {
            $prompt .= "\n".$this->options($d);
        }
        if (in_array($f, ['unit_id', 'store_category_id', 'add_ons', 'attribute_ids'], true)) {
            $q = DB::table(match ($f) {
                'unit_id' => 'units','attribute_ids' => 'attributes','store_category_id' => 'store_categories',default => 'add_ons'
            });
            if (! in_array($f, ['unit_id', 'attribute_ids'], true)) {
                $q->where('store_id', $d->store_id);
            }
            $prompt .= "\n".$q->limit(20)->get()->map(fn ($r) => $r->id.': '.($r->name ?? $r->unit))->implode("\n");
        }
        if ($f === 'tax_ids') {
            $prompt .= collect(Helpers::getTaxSystemType()['taxVats'])->map(fn ($t) => $t->id.': '.$t->name.' ('.$t->tax_rate.'%)')->implode("\n");
        }
        $suggest = $d->vision['suggestions'][$f] ?? null;
        if ($suggest !== null) {
            $prompt .= "\nAI suggestion (unconfirmed; check carefully): ".(is_scalar($suggest) ? $suggest : json_encode($suggest)).'. Send the correct value to confirm or correct it.';
        }

        return $prompt."\nBack • Save draft • Cancel • Support";
    }

    public function options(Draft $d, string $search = ''): string
    {
        $q = $this->fields->categories($d->store, $d->step === 'subcategory_id' ? (int) ($d->data['category_id'] ?? 0) : 0);
        $aliases = ['body cream' => 'skin care', 'cream' => 'skin care', 'ear pod' => 'audio', 'earphone' => 'audio', 'headphone' => 'audio', 'clothing' => 'fashion', 'necklace' => 'accessories'];
        foreach ($aliases as $term => $mapped) {
            if (str_contains(mb_strtolower($search), $term)) {
                $search = $mapped;
                break;
            }
        }
        if ($search !== '') {
            $matches=$this->fields->categories($d->store)->where('name','like','%'.addcslashes($search,'%_\\').'%')->get(['id','parent_id']);
            $ids=$matches->map(fn($c)=>$d->step==='subcategory_id'?$c->id:($c->parent_id?:$c->id))->all();
            $q->whereIn('id',$ids);
        }
        $rows = $q->limit(25)->get();

        return $rows->isEmpty() ? 'No matching category is available. Try Options with another word, Save draft or Support.' : $rows->map(fn ($r) => $r->id.': '.$r->name)->implode("\n");
    }

    public function create(Draft $d, WhatsAppContact $contact): string
    {
        return DB::transaction(function () use ($d, $contact) {
            $locked = Draft::lockForUpdate()->findOrFail($d->id);
            if ($locked->item_id) {
                return 'Already created: product #'.$locked->item_id;
            }
            abort_unless($locked->status === 'active' && $locked->step === 'review' && $locked->contact_id === $contact->id, 409);
            abort_unless((int) $locked->store->module_id === (int) $locked->module_id && $locked->store->status && (int) $locked->store->vendor->status === 1, 409, 'Shop status or module changed; contact support to review this draft.');
            $data = $locked->data;
            unset($data['image']);
            if (! empty($data['subcategory_id'])) {
                $data['category_id'] = $data['subcategory_id'];
            }$data['discount_type'] = 'percent';
            $data['attributes'] = $data['attribute_ids'] ?? [];
            $data['choice_options'] = [];
            $data['variations'] = [];
            foreach ($data['attributes'] as $id) {
                $data['choice_options'][] = ['name' => 'choice_'.$id, 'title' => DB::table('attributes')->where('id', $id)->value('name'), 'options' => $data['choice_'.$id]];
            }
            foreach ($this->fields->combinations($data) as $i => $label) {
                $data['variations'][] = ['type' => $label, 'price' => $data['variant_price_'.$i], 'stock' => $data['variant_stock_'.$i]];
            }
            if ($data['variations']) {
                $data['stock'] = array_sum(array_column($data['variations'], 'stock'));
            }
            $data['food_variations'] = [];
            for ($i = 0; $i < ($data['food_group_count'] ?? 0); $i++) {
                $prefix = 'food_'.$i.'_';
                $min = $data[$prefix.'min'];
                $max = $data[$prefix.'max'];
                $required = $data[$prefix.'required'];
                $options = $data[$prefix.'options'];
                if ($max < 1 || $min > $max || $max > count($options) || ($required === 'on' && $min < 1)) {
                    throw ValidationException::withMessages(['food_'.$i.'_min' => 'Check option group minimum, maximum and required setting.']);
                }
                $values = [];
                foreach ($options as $j => $label) {
                    $values[] = ['label' => $label, 'optionPrice' => $data[$prefix.'price_'.$j]];
                }
                $data['food_variations'][] = ['name' => $data[$prefix.'name'], 'type' => $max === 1 ? 'single' : 'multi', 'min' => $min, 'max' => $max, 'required' => $required, 'values' => $values];
            }
            $item = app(ProductMutationService::class)->createProduct($locked->store,(int) $contact->vendor_id,$data);
            $locked->update(['status' => 'completed', 'item_id' => $item->id, 'errors' => []]);

            return 'Product #'.$item->id.' created: '.$item->name.'. '.($item->relationLoaded('conciergeReview') ? 'Awaiting admin approval.' : 'Published in your shop.');
        });
    }
}
