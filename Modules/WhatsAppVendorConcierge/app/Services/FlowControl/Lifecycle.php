<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\FlowControl;

use App\Models\Admin;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\WhatsAppVendorConcierge\app\Jobs\RunFlowControlOperation;
use Modules\WhatsAppVendorConcierge\app\Services\FlowDefinitionValidator;
use Modules\WhatsAppVendorConcierge\app\Services\FlowMetaClient;

class Lifecycle
{
    public const ACTIONS = ['token_audit' => 'diagnostics', 'inspect' => 'view', 'compare' => 'validate', 'health' => 'diagnostics', 'local' => 'validate', 'remote' => 'validate', 'create' => 'drafts', 'upload' => 'drafts', 'publish' => 'publish', 'deprecate' => 'publish', 'key_check' => 'diagnostics', 'key_configure' => 'publish', 'ping' => 'diagnostics', 'subscriptions' => 'diagnostics', 'reconcile_preview' => 'diagnostics', 'reconcile' => 'recovery', 'retry_processing' => 'recovery', 'credential_resend' => 'recovery'];

    public function enqueue(int $admin, string $action, string $nonce, ?int $application = null, ?string $obsoleteFlow = null): string
    {
        app(RuntimeSettings::class)->apply();
        if (! isset(self::ACTIONS[$action])) {
            throw new Failure('Unsupported lifecycle action.');
        }
        $definition = app(FlowDefinitionValidator::class);
        $hash = hash_file('sha256', $definition->path());
        $version = (string) config('whatsapp-vendor-flow.definition_version');
        $sync = DB::table('wa_vendor_flow_sync')->where('definition_version', $version)->first();
        $context = ['endpoint' => (string) config('whatsapp-vendor-flow.endpoint_url'), 'graph_version' => config('whatsapp-vendor-flow.graph_version'), 'environment' => app()->environment(), 'definition_version' => $version, 'asset_hash' => $hash, 'waba' => (string) config('whatsapp-vendor-concierge.api.business_account_id'), 'phone_id' => (string) config('whatsapp-vendor-concierge.api.phone_number_id')];
        if (in_array($action, ['ping', 'key_check', 'key_configure'], true)) {
            $context['public_key_hash'] = hash('sha256', preg_replace('/\s+/', '', app(EndpointProbe::class)->key()->getPublicKey()->toString('PKCS8')));
        }
        if ($obsoleteFlow !== null) {
            $context['obsolete_flow'] = $obsoleteFlow;
        }
        if ($application !== null) {
            $context['application_id'] = $application;
        }

        return DB::transaction(function () use ($admin, $action, $nonce, $context, $sync) {
            $key = hash('sha256', $admin.'|'.$action.'|'.$nonce.'|'.($context['application_id'] ?? '').'|'.($context['obsolete_flow'] ?? ''));
            $existing = DB::table('wa_flow_control_operations')->where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing->id;
            }
            $id = (string) Str::uuid();
            $target = isset($context['application_id']) ? 'session:'.$context['application_id'] : ($context['obsolete_flow'] ?? $sync?->draft_flow_id);
            $inserted = DB::table('wa_flow_control_operations')->insertOrIgnore(['id' => $id, 'admin_id' => $admin, 'action' => $action, 'target' => $target, 'idempotency_key' => $key, 'state' => 'queued', 'context' => json_encode($context), 'created_at' => now(), 'updated_at' => now()]);
            if (! $inserted) {
                return DB::table('wa_flow_control_operations')->where('idempotency_key', $key)->value('id');
            }
            app(Audit::class)->record($admin, $action, $target, 'queued', $context, $id);
            RunFlowControlOperation::dispatch($id);

            return $id;
        });
    }

    public function run(string $id): void
    {
        app(RuntimeSettings::class)->apply();
        $op = DB::table('wa_flow_control_operations')->where('id', $id)->first();
        if (! $op || $op->state !== 'queued') {
            return;
        }
        $lock = Cache::lock('wa-flow-lifecycle', 150);
        if (! $lock->get()) {
            throw new \RuntimeException('Another lifecycle operation is running; retry after inspecting history.');
        }
        try {
            if (! DB::table('wa_flow_control_operations')->where('id', $id)->where('state', 'queued')->update(['state' => 'running', 'updated_at' => now()])) {
                return;
            }
            $ctx = json_decode($op->context, true, 16, JSON_THROW_ON_ERROR);
            $admin = Admin::withoutGlobalScopes()->select('id', 'role_id')->find($op->admin_id);
            if (! $admin || ! app(Permissions::class)->allows($admin, self::ACTIONS[$op->action])) {
                throw new Failure('Administrator permission was revoked before execution.');
            }
            $validator = app(FlowDefinitionValidator::class);
            if ($ctx['asset_hash'] !== hash_file('sha256', $validator->path()) || $ctx['definition_version'] !== config('whatsapp-vendor-flow.definition_version') || $ctx['waba'] !== (string) config('whatsapp-vendor-concierge.api.business_account_id') || $ctx['phone_id'] !== (string) config('whatsapp-vendor-concierge.api.phone_number_id')) {
                throw new Failure('Configuration changed since confirmation. Review a fresh operation.');
            }
            if (($ctx['endpoint'] ?? '') !== (string) config('whatsapp-vendor-flow.endpoint_url') || ($ctx['graph_version'] ?? null) !== config('whatsapp-vendor-flow.graph_version') || ($ctx['environment'] ?? null) !== app()->environment()) {
                throw new Failure('Deployment target changed since review.');
            }
            if (isset($ctx['public_key_hash']) && $ctx['public_key_hash'] !== hash('sha256', preg_replace('/\s+/', '', app(EndpointProbe::class)->key()->getPublicKey()->toString('PKCS8')))) {
                throw new Failure('Encryption key changed since review.');
            }
            $result = $this->execute($op, $ctx, $validator);
            $this->finish($op, 'succeeded', $result);
        } catch (MetaError $e) {
            $this->finish($op, 'failed', $e->safe);
        } catch (\Throwable $e) {
            $this->finish($op, 'failed', ['title' => 'Operation did not complete', 'message' => $e instanceof Failure ? $e->getMessage() : 'Inspect remote state and configuration before retrying; arbitrary exception details are withheld.', 'retry_safe' => false]);
        } finally {
            $lock->release();
        }
    }

    private function execute(object $op, array $ctx, FlowDefinitionValidator $validator): array
    {
        if ($op->action === 'deprecate') {
            $target = $ctx['obsolete_flow'] ?? null;
            $current = DB::table('wa_vendor_flow_sync')->where('definition_version', $ctx['definition_version'])->first();
            $lastWorking = DB::table('wa_vendor_flow_sync')->whereNotNull('published_flow_id')->latest('updated_at')->value('published_flow_id');
            if (! $target || ! DB::table('wa_vendor_flow_sync')->where('published_flow_id', $target)->exists() || in_array($target, [config('whatsapp-vendor-flow.flow_id'), $current?->published_flow_id, $lastWorking], true)) {
                throw new Failure('Only a recorded obsolete published Flow can be deprecated; active and last working pointers are protected.');
            }
            if (DB::table('wa_vendor_flow_sessions')->where('flow_id', $target)->whereNull('consumed_at')->where('expires_at', '>', now())->exists()) {
                throw new Failure('Active sessions still depend on this Flow.');
            }
            $meta = app(FlowMetaClient::class);
            $remote = $meta->request('GET', $target, ['fields' => 'id,status']);
            if (($remote['status'] ?? '') === 'DEPRECATED') {
                return ['flow_id' => $target, 'status' => 'DEPRECATED'];
            }
            if (($remote['status'] ?? '') !== 'PUBLISHED') {
                throw new Failure('Only an obsolete published Flow can be deprecated.');
            }
            if (($meta->request('POST', $target.'/deprecate')['success'] ?? false) !== true) {
                throw new Failure('Deprecation was not confirmed; inspect remote state.');
            }

            return ['flow_id' => $target, 'status' => 'DEPRECATED', 'dispatch_changed' => false];
        }
        if ($op->action === 'token_audit') {
            return app(Diagnostics::class)->tokenAudit();
        }

        $meta = app(FlowMetaClient::class);
        if (in_array($op->action, ['retry_processing', 'credential_resend'], true)) {
            if (! isset($ctx['application_id'])) {
                throw new Failure('A specific application is required.');
            }

            return app(RegistrationRecovery::class)->run((int) $ctx['application_id'], $op->action);
        }
        if (in_array($op->action, ['reconcile', 'reconcile_preview'], true)) {
            return app(MediaReconciliation::class)->run($op->action === 'reconcile');
        }
        if ($op->action === 'ping') {
            return app(EndpointProbe::class)->run();
        }
        if ($op->action === 'subscriptions') {
            $r = $meta->request('GET', $ctx['waba'].'/subscribed_apps');

            return ['subscribed_application_count' => count($r['data'] ?? []), 'note' => 'WABA subscriptions observed. App-level webhook field configuration requires the separately authorized app credential boundary.'];
        }
        if ($op->action === 'local') {
            $validator->validate();

            return ['validation' => 'passed', 'asset_hash' => $ctx['asset_hash']];
        }
        if ($op->action === 'key_check' || $op->action === 'key_configure') {
            $r = $meta->request('GET', $ctx['phone_id'].'/whatsapp_business_encryption');
            $key = app(EndpointProbe::class)->key();
            $public = $key->getPublicKey()->toString('PKCS8');
            $remotePublic = $r['data'][0]['business_public_key'] ?? '';
            $same = $remotePublic !== '' && preg_replace('/\s+/', '', $remotePublic) === preg_replace('/\s+/', '', $public);
            if ($op->action === 'key_configure' && ! $same) {
                if (DB::table('wa_vendor_flow_sessions')->whereNull('consumed_at')->where('expires_at', '>', now())->exists()) {
                    throw new Failure('Active sessions prevent encryption key replacement.');
                }
                $phones = $meta->request('GET', $ctx['waba'].'/phone_numbers', ['fields' => 'id']);
                if (! collect($phones['data'] ?? [])->contains('id', $ctx['phone_id'])) {
                    throw new Failure('Phone is not owned by the target WABA.');
                }
                $meta->request('POST', $ctx['phone_id'].'/whatsapp_business_encryption', ['business_public_key' => $public]);
                $r = $meta->request('GET', $ctx['phone_id'].'/whatsapp_business_encryption');
                $same = preg_replace('/\s+/', '', $r['data'][0]['business_public_key'] ?? '') === preg_replace('/\s+/', '', $public);
            }

            return ['key_signature_status' => ($r['data'][0]['business_public_key_signature_status'] ?? '') === 'VALID' ? 'VALID' : 'unknown', 'matches_configured_private_key' => $same];
        }
        if ($op->action === 'health') {
            $r = $meta->request('GET', $ctx['waba'].'/phone_numbers', ['fields' => 'id,display_phone_number,quality_rating,status']);
            $phone = collect($r['data'] ?? [])->firstWhere('id', $ctx['phone_id']);

            return ['phone' => $phone ? array_intersect_key($phone, array_flip(['display_phone_number', 'quality_rating', 'status'])) : [], 'connection' => $phone ? 'connected' : 'unverified'];
        }
        $sync = DB::table('wa_vendor_flow_sync')->where('definition_version', $ctx['definition_version'])->first();
        $flow = $sync?->draft_flow_id;
        if ($op->action === 'create') {
            if (! $flow) {
                $name = config('whatsapp-vendor-flow.sync_name').' '.$ctx['definition_version'];
                $matches = [];
                $after = null;
                $pages = 0;
                do {
                    if (++$pages > 3) {
                        throw new Failure('Draft inventory exceeds the bounded discovery limit. Inspect the account inventory before creating a replacement.');
                    }
                    $r = $meta->request('GET', $ctx['waba'].'/flows', array_filter(['fields' => 'id,name,status', 'limit' => 100, 'after' => $after]));
                    foreach ($r['data'] ?? [] as $candidate) {
                        if (($candidate['name'] ?? '') === $name) {
                            $matches[] = $candidate;
                        }
                    }
                    $after = isset($r['paging']['next']) ? ($r['paging']['cursors']['after'] ?? null) : null;
                } while ($after);
                if (count($matches) > 1) {
                    throw new Failure('Multiple matching drafts need reconciliation.');
                }
                if ($matches && $matches[0]['status'] !== 'DRAFT') {
                    throw new Failure('Use a new reviewed definition version for an immutable Flow.');
                }
                $endpoint = (string) config('whatsapp-vendor-flow.endpoint_url');
                if (! str_starts_with($endpoint, 'https://')) {
                    throw new Failure('A configured HTTPS data endpoint is required.');
                }
                $flow = $matches ? $matches[0]['id'] : ($meta->request('POST', $ctx['waba'].'/flows', ['name' => $name, 'categories' => ['SIGN_UP'], 'endpoint_uri' => $endpoint])['id'] ?? null);
                if (! $flow || ! ctype_digit((string) $flow)) {
                    throw new Failure('Meta did not return a valid draft identity.');
                }
                DB::table('wa_vendor_flow_sync')->updateOrInsert(['definition_version' => $ctx['definition_version']], ['draft_flow_id' => $flow, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
            }

            return ['flow_id' => $flow, 'status' => 'draft', 'dispatch_changed' => false];
        }
        if (! $flow || ($op->target && $op->target !== $flow)) {
            throw new Failure('Draft target changed or is absent. Inspect before retrying.');
        }
        if ($op->action === 'compare') {
            return app(RemoteDefinition::class)->compare($flow);
        }
        $remote = $meta->request('GET', $flow, ['fields' => 'id,name,status,validation_errors,json_version,data_api_version,health_status']);
        $safe = ['flow_id' => $flow, 'status' => in_array($remote['status'] ?? '', ['DRAFT', 'PUBLISHED', 'DEPRECATED', 'BLOCKED', 'THROTTLED'], true) ? $remote['status'] : 'UNKNOWN', 'validation_error_count' => count($remote['validation_errors'] ?? []), 'json_version' => $remote['json_version'] ?? null, 'data_api_version' => $remote['data_api_version'] ?? null];
        $healthCodes = [];
        $health = $remote['health_status'] ?? [];
        array_walk_recursive($health, function ($value, $key) use (&$healthCodes) {
            if ($key === 'error_code' || $key === 'code') {
                $healthCodes[] = (int) $value;
            }
        });
        $safe['health_codes'] = array_values(array_unique($healthCodes));
        $safe['health_available'] = ($health['can_send_message'] ?? '') === 'AVAILABLE' && $healthCodes === [];

        $safe['validation_locations'] = collect($remote['validation_errors'] ?? [])->take(25)->map(function ($error) {
            $code = $error['error'] ?? $error['error_type'] ?? '';
            $location = ['type' => is_string($code) && preg_match('/^[A-Z_0-9]{1,80}$/D', $code) ? $code : 'VALIDATION_ERROR'];
            foreach (['line_start', 'line_end', 'column_start', 'column_end'] as $field) {
                if (isset($error[$field]) && is_numeric($error[$field])) {
                    $location[$field] = max(0, (int) $error[$field]);
                }
            }

            return $location;
        })->all();

        if (in_array($op->action, ['inspect', 'remote'], true)) {
            if ($safe['status'] === 'PUBLISHED' && $sync->asset_hash === $ctx['asset_hash'] && $safe['validation_error_count'] === 0) {
                DB::table('wa_vendor_flow_sync')->where('definition_version', $ctx['definition_version'])->update(['published_flow_id' => $flow, 'status' => 'published', 'error_code' => null, 'updated_at' => now()]);
            }
            $safe['local_asset_hash'] = $ctx['asset_hash'];
            $safe['last_acknowledged_remote_upload_hash'] = $sync->asset_hash;
            $safe['upload_journal_matches_local'] = $sync->asset_hash === $ctx['asset_hash'];
            $safe['remote_byte_comparison'] = 'Not verified; the upload acknowledgement journal is compared, not downloaded remote bytes.';

            return $safe;
        }
        if ($op->action === 'upload') {
            if ($safe['status'] !== 'DRAFT') {
                throw new Failure('Published or deprecated Flows are immutable; create a new definition version.');
            }
            $validator->validate();
            if ($sync->asset_hash !== $ctx['asset_hash'] || ! app(RemoteDefinition::class)->compare($flow)['definitions_match']) {
                $uploaded = $meta->request('POST', $flow.'/assets', ['name' => 'flow.json', 'asset_type' => 'FLOW_JSON'], file_get_contents($validator->path()));
                if (($uploaded['success'] ?? false) !== true || ! empty($uploaded['validation_errors'])) {
                    throw new Failure('Meta rejected the asset. Inspect validation; published pointer retained.');
                }
                DB::table('wa_vendor_flow_sync')->where('definition_version', $ctx['definition_version'])->update(['asset_hash' => $ctx['asset_hash'], 'status' => 'validated', 'error_code' => null, 'updated_at' => now()]);
            }

            return $safe + ['asset_hash' => $ctx['asset_hash']];
        }
        if ($op->action === 'publish') {
            $validator->validate();
            if (in_array(141010, $safe['health_codes'], true)) {
                throw MetaError::fromResponse(400, ['code' => 141010]);
            }
            if (! $safe['health_available']) {
                throw new Failure('Flow health is unknown or unavailable. Refresh health and resolve the restriction before publication.');
            }
            if ($sync->asset_hash !== $ctx['asset_hash'] || $safe['validation_error_count']) {
                throw new Failure('Reviewed local and validated remote asset must match before publication.');
            }
            if (! app(RemoteDefinition::class)->compare($flow)['definitions_match']) {
                throw new Failure('Remote definition differs from the reviewed local contract. Upload and compare before publication.');
            }
            if ($safe['status'] !== 'PUBLISHED') {
                if ($safe['status'] !== 'DRAFT') {
                    throw new Failure('Only a validated draft can be published.');
                }
                $r = $meta->request('POST', $flow.'/publish');
                if (($r['success'] ?? false) !== true) {
                    throw new Failure('Publication was not confirmed; inspect remote state.');
                }
            }
            DB::table('wa_vendor_flow_sync')->where('definition_version', $ctx['definition_version'])->update(['published_flow_id' => $flow, 'status' => 'published', 'error_code' => null, 'updated_at' => now()]);

            return ['flow_id' => $flow, 'status' => 'PUBLISHED', 'dispatch_changed' => false];
        }
        throw new Failure('Unsupported operation.');
    }

    private function finish(object $op, string $state, array $result): void
    {
        DB::transaction(function () use ($op, $state, $result) {
            DB::table('wa_flow_control_operations')->where('id', $op->id)->update(['state' => $state, 'result' => json_encode($result), 'updated_at' => now()]);
            if ($state === 'failed' && isset($result['code'])) {
                $ctx = json_decode($op->context, true);
                DB::table('wa_vendor_flow_sync')->where('definition_version', $ctx['definition_version'])->update(['error_code' => 'meta_rejected', 'last_error_metadata' => json_encode($result + ['action' => $op->action, 'observed_at' => now('UTC')->toISOString()]), 'updated_at' => now()]);
            }
            app(Audit::class)->record($op->admin_id, $op->action, $op->target, $state, $result, $op->id);
        });
    }
}
