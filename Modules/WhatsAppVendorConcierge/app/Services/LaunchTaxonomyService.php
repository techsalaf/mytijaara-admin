<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use App\Models\Category;
use App\Models\Module;
use App\Models\Translation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\WhatsAppVendorConcierge\app\Models\ConciergeRecoveryAudit;

class LaunchTaxonomyService
{
    public function run(bool $apply = false, ?int $moduleId = null, bool $lookups = false): array
    {
        return Cache::lock('concierge-launch-taxonomy', 120)->block(5, function () use ($apply, $moduleId, $lookups) {
            $dataset = json_decode(file_get_contents(module_path('WhatsAppVendorConcierge', 'resources/data/launch-taxonomy.json')), true, flags: JSON_THROW_ON_ERROR);
            $backup = null;
            if ($apply) {
                $backup = 'private/maintenance/taxonomy-'.now()->format('Ymd-His').'-'.Str::random(8).'.json';
                Storage::disk('local')->put($backup, json_encode(['units' => DB::table('units')->get(), 'attributes' => DB::table('attributes')->get(), 'categories' => DB::table('categories')->get(), 'translations' => DB::table('translations')->where('translationable_type', Category::class)->get(), 'keys' => DB::table('whatsapp_taxonomy_keys')->get()], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                if (! Storage::disk('local')->exists($backup)) {
                    throw new \RuntimeException('Backup failed');
                }
            }
            $manifest = DB::transaction(function () use ($dataset, $apply, $moduleId, $lookups) {
                $rows = [];
                if ($lookups) {
                    foreach (['units' => ['column' => 'unit', 'values' => ['Piece', 'Pack', 'Pair', 'Kilogram', 'Gram', 'Litre', 'Millilitre']], 'attributes' => ['column' => 'name', 'values' => ['Colour', 'Size', 'Material']]] as $table => $lookup) {
                        foreach ($lookup['values'] as $value) {
                            $existing = DB::table($table)->whereRaw('LOWER('.$lookup['column'].') = ?', [mb_strtolower($value)])->get();
                            $action = $existing->count() > 1 ? 'conflict' : ($existing->count() === 1 ? 'matched' : 'created');
                            $id = $existing->first()?->id;
                            if ($apply && $action === 'created') {
                                $id = DB::table($table)->insertGetId([$lookup['column'] => $value, 'created_at' => now(), 'updated_at' => now()]);
                            }
                            $rows[] = ['kind' => $table, 'key' => $table.'/'.Str::slug($value), 'name' => $value, 'id' => $id, 'action' => $action];
                        }
                    }
                }
                foreach (Module::where('status', 1)->when($moduleId, fn ($q) => $q->whereKey($moduleId))->get() as $module) {
                    if (! isset($dataset[$module->module_type])) {
                        $rows[] = ['module' => $module->id, 'action' => 'skipped', 'reason' => 'Module does not use canonical item categories'];

                        continue;
                    }
                    foreach ($dataset[$module->module_type] as $parent => $children) {
                        $parentId = $this->record($module->id, $parent, 0, Str::slug($parent), $apply, $rows);
                        foreach ($children as $child) {
                            $this->record($module->id, $child, $parentId, Str::slug($parent).'/'.Str::slug($child), $apply, $rows);
                        }
                    }
                }if ($apply && in_array('conflict', array_column($rows, 'action'), true)) {
                    throw new \RuntimeException('Taxonomy conflict: no records committed. Inspect dry-run manifest.');
                }

return $rows;
            });
            if ($apply) {
                Cache::forget('categories');
                ConciergeRecoveryAudit::create(['action' => 'launch_taxonomy_import', 'initiated_by' => 'cli', 'is_dry_run' => false, 'status' => 'success', 'reason' => 'Owner requested additive launch taxonomy', 'details' => ['backup' => $backup, 'counts' => array_count_values(array_column($manifest, 'action'))], 'correlation_id' => 'TAX-'.Str::uuid()]);
            }

            return ['apply' => $apply, 'backup' => $backup, 'counts' => array_count_values(array_column($manifest, 'action')), 'records' => $manifest, 'images' => 'New records use the canonical def.png sentinel; admin must supply category images.'];
        });
    }

    private function record(int $module, string $name, ?int $parent, string $key, bool $apply, array &$rows): ?int
    {
        $mapped = DB::table('whatsapp_taxonomy_keys')->where('module_id', $module)->where('stable_key', $key)->first();
        $q = Category::withoutGlobalScopes()->where('module_id', $module)->where('parent_id', $parent ?? -1);
        $candidates = $mapped ? Category::withoutGlobalScopes()->whereKey($mapped->category_id)->get() : $q->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->get();
        $action = 'created';
        $id = null;
        if ($candidates->count() > 1 || ($mapped && ($candidates->count() !== 1 || (int) $candidates->first()->module_id !== $module || (int) $candidates->first()->parent_id !== $parent))) {
            $action = 'conflict';
        } elseif ($candidates->count() === 1) {
            $id = $candidates->first()->id;
            $action = 'matched';
        } elseif ($parent === null && $apply) {
            $action = 'skipped';
        } elseif ($apply) {
            $c = new Category;
            $c->name = $name;
            $c->module_id = $module;
            $c->parent_id = $parent;
            $c->position = $parent === 0 ? 0 : 1;
            $c->status = 1;
            $c->priority = 0;
            $c->image = 'def.png';
            $c->save();
            $id = $c->id;
            Translation::updateOrCreate(['translationable_type' => Category::class, 'translationable_id' => $id, 'locale' => 'en', 'key' => 'name'], ['value' => $name]);
        }
        if ($apply && $id && ! $mapped) {
            DB::table('whatsapp_taxonomy_keys')->insert(['module_id' => $module, 'stable_key' => $key, 'category_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $rows[] = ['module' => $module, 'key' => $key, 'name' => $name, 'parent_id' => $parent, 'action' => $action, 'id' => $id, 'image_required' => true];

        return $id;
    }
}
