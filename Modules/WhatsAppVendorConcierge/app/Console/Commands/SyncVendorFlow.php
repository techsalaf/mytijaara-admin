<?php

namespace Modules\WhatsAppVendorConcierge\app\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\WhatsAppVendorConcierge\app\Services\FlowControl\MetaError;
use Modules\WhatsAppVendorConcierge\app\Services\FlowControl\RuntimeSettings;
use Modules\WhatsAppVendorConcierge\app\Services\FlowDefinitionValidator;
use Modules\WhatsAppVendorConcierge\app\Services\FlowMetaClient;

class SyncVendorFlow extends Command
{
    protected $signature = 'whatsapp:flow-sync {--action=status : status, local, create, update, upload, remote} {--execute : Execute requested draft mutation} {--publish : Explicitly publish the validated draft}';

    protected $description = 'Inspect or synchronize a versioned draft; no mutation or publication by default.';

    public function handle(FlowDefinitionValidator $v, FlowMetaClient $meta): int
    {
        app(RuntimeSettings::class)->apply();
        $action = $this->option('action');
        if (! in_array($action, ['status', 'local', 'create', 'update', 'upload', 'remote'], true)) {
            $this->error('Unknown synchronization action.');

            return self::FAILURE;
        }
        try {
            $v->validate();
            $version = config('whatsapp-vendor-flow.definition_version');
            $asset = file_get_contents($v->path());
            $hash = hash('sha256', $asset);
            if ($action === 'local') {
                $this->info('Local validation passed. No Meta calls.');

                return self::SUCCESS;
            }
            if ($this->option('publish') && ! $this->option('execute')) {
                $this->error('Publication requires --execute and --publish.');

                return self::FAILURE;
            }

            if ($action === 'status' && ! $this->option('publish')) {
                $row = DB::table('wa_vendor_flow_sync')->where('definition_version', $version)->first();
                $this->line(json_encode($row ? array_intersect_key((array) $row, array_flip(['definition_version', 'draft_flow_id', 'published_flow_id', 'status', 'error_code', 'updated_at'])) : ['definition_version' => $version, 'status' => 'unsynchronized']));

                return self::SUCCESS;
            }
            if (in_array($action, ['create', 'update', 'upload'], true) && ! $this->option('execute')) {
                $this->info('Dry run: '.$action.' draft; published Flow retained. No Meta mutation.');

                return self::SUCCESS;
            }

            return DB::transaction(function () use ($action, $version, $asset, $hash, $meta) {
                DB::table('wa_vendor_flow_sync')->insertOrIgnore(['definition_version' => $version, 'status' => 'unsynchronized', 'created_at' => now(), 'updated_at' => now()]);
                $row = DB::table('wa_vendor_flow_sync')->where('definition_version', $version)->lockForUpdate()->first();
                $draft = $row->draft_flow_id ?? null;
                $freshDraft = false;
                if ($draft) {
                    $status = $meta->request('GET', $draft, ['fields' => 'id,status,validation_errors']);
                    if (($status['status'] ?? '') === 'PUBLISHED') {
                        if (($row->asset_hash ?? null) === $hash) {
                            $this->info('This definition is already published; no mutation performed.');

                            return self::SUCCESS;
                        }throw new \RuntimeException('Published definitions require a new definition version.');
                    }
                }
                if ($action === 'create' || (! $draft && in_array($action, ['update', 'upload'], true))) {
                    if (! $draft) {
                        // Recover a remotely created draft after an ambiguous network/database failure.
                        $name = config('whatsapp-vendor-flow.sync_name').' '.$version;
                        $after = null;
                        $matches = [];
                        do {
                            $query = ['fields' => 'id,name,status', 'limit' => 100];
                            if ($after) {
                                $query['after'] = $after;
                            }
                            $listed = $meta->request('GET', (string) config('whatsapp-vendor-concierge.api.business_account_id').'/flows', $query);
                            foreach ($listed['data'] ?? [] as $candidate) {
                                if (($candidate['name'] ?? null) === $name) {
                                    $matches[] = $candidate;
                                }
                            }
                            $after = isset($listed['paging']['next']) ? ($listed['paging']['cursors']['after'] ?? null) : null;
                        } while ($after);
                        if (count($matches) > 1) {
                            throw new \RuntimeException('Multiple matching drafts require manual reconciliation.');
                        }
                        if ($matches) {
                            if (($matches[0]['status'] ?? '') !== 'DRAFT') {
                                throw new \RuntimeException('Recovered Flow is immutable; inspect it before proceeding.');
                            }$draft = (string) $matches[0]['id'];
                        }
                        $endpoint = (string) config('whatsapp-vendor-flow.endpoint_url');
                        if (! str_starts_with($endpoint, 'https://')) {
                            throw new \RuntimeException('A trusted HTTPS endpoint URL is required.');
                        }
                        if (! $draft) {
                            $created = $meta->request('POST', (string) config('whatsapp-vendor-concierge.api.business_account_id').'/flows', ['name' => config('whatsapp-vendor-flow.sync_name').' '.$version, 'categories' => ['SIGN_UP'], 'endpoint_uri' => $endpoint]);
                            $draft = (string) ($created['id'] ?? '');
                            $freshDraft = true;
                            if (! $draft) {
                                throw new \RuntimeException('Meta did not return a draft ID.');
                            }
                        }
                        DB::table('wa_vendor_flow_sync')->updateOrInsert(['definition_version' => $version], ['draft_flow_id' => $draft, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
                if (! $draft) {
                    throw new \RuntimeException('No mutable draft exists for this definition.');
                }
                if ($action === 'update') {
                    $meta->request('POST', $draft, ['name' => config('whatsapp-vendor-flow.sync_name').' '.$version, 'endpoint_uri' => config('whatsapp-vendor-flow.endpoint_url')]);
                }
                if (in_array($action, ['create', 'update', 'upload'], true) && ($freshDraft || ($row->asset_hash ?? null) !== $hash)) {
                    $uploaded = $meta->request('POST', $draft.'/assets', ['name' => 'flow.json', 'asset_type' => 'FLOW_JSON'], $asset);
                    if (($uploaded['success'] ?? false) !== true || ! empty($uploaded['validation_errors'])) {
                        throw new \RuntimeException('Meta asset validation rejected: '.$this->safeValidationErrors($uploaded['validation_errors'] ?? []));
                    }
                    DB::table('wa_vendor_flow_sync')->where('definition_version', $version)->update(['asset_hash' => $hash, 'status' => 'validated', 'error_code' => null, 'updated_at' => now()]);
                }
                if ($action === 'remote' || $this->option('publish')) {
                    $detail = $meta->request('GET', $draft, ['fields' => 'id,status,validation_errors,json_version,data_api_version,health_status']);
                    if (! empty($detail['validation_errors'])) {
                        throw new \RuntimeException('Meta remote validation rejected: '.$this->safeValidationErrors($detail['validation_errors']));
                    }
                    if ($this->option('publish')) {
                        $fresh = DB::table('wa_vendor_flow_sync')->where('definition_version', $version)->first();
                        if (($fresh->asset_hash ?? null) !== $hash) {
                            throw new \RuntimeException('Draft asset hash does not match the reviewed definition.');
                        }
                        $published = $meta->request('POST', $draft.'/publish');
                        if (($published['success'] ?? false) !== true) {
                            throw new \RuntimeException('Meta publication was not confirmed.');
                        }
                        DB::table('wa_vendor_flow_sync')->where('definition_version', $version)->update(['published_flow_id' => $draft, 'status' => 'published', 'error_code' => null, 'updated_at' => now()]);
                    }
                }
                $this->info('Synchronization completed. Runtime Flow ID/configuration is unchanged; activate separately.');

                return self::SUCCESS;
            });
        } catch (\Throwable $e) {
            // Exception text originates only from our sanitized Meta client or local invariants.
            $this->error($e instanceof QueryException ? 'Synchronization storage unavailable; apply approved migrations in the intended environment.' : ($e instanceof \RuntimeException ? $e->getMessage() : 'Synchronization failed: '.$e::class));
            if (isset($version) && Schema::hasTable('wa_vendor_flow_sync')) {
                $failure = ['error_code' => 'sync_failed', 'updated_at' => now()];
                if ($e instanceof MetaError && Schema::hasColumn('wa_vendor_flow_sync', 'last_error_metadata')) {
                    $failure['last_error_metadata'] = json_encode($e->safe + ['action' => $this->option('publish') ? 'publish' : $action, 'observed_at' => now('UTC')->toISOString()]);
                }
                DB::table('wa_vendor_flow_sync')->where('definition_version', $version)->update($failure);
            }

            return self::FAILURE;
        }
    }

    private function safeValidationErrors(array $errors): string
    {
        $out = [];
        foreach (array_slice($errors, 0, 10) as $error) {
            $type = $error['error_type'] ?? $error['error'] ?? 'VALIDATION_ERROR';
            if (! is_string($type) || ! preg_match('/^[A-Z0-9_]{1,64}$/D', $type)) {
                $type = 'VALIDATION_ERROR';
            }
            $line = filter_var($error['line_start'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
            $out[] = $type.($line ? ' at line '.$line : '');
        }

        return count($errors).' error(s): '.implode('; ', $out).'. Arbitrary reflected Meta text is redacted.';
    }
}
