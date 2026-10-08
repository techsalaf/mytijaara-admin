<?php

namespace Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PrivateRegistrationStorageGuard;
use App\Services\RegistrationPolicyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\WhatsAppVendorConcierge\app\Jobs\RunFlowControlOperation;
use Modules\WhatsAppVendorConcierge\app\Models\VendorFlowSession;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Services\FlowControl\Analytics;
use Modules\WhatsAppVendorConcierge\app\Services\FlowControl\ApplicationRecovery;
use Modules\WhatsAppVendorConcierge\app\Services\FlowControl\Audit;
use Modules\WhatsAppVendorConcierge\app\Services\FlowControl\Confirmation;
use Modules\WhatsAppVendorConcierge\app\Services\FlowControl\Diagnostics;
use Modules\WhatsAppVendorConcierge\app\Services\FlowControl\Lifecycle;
use Modules\WhatsAppVendorConcierge\app\Services\FlowControl\Permissions;
use Modules\WhatsAppVendorConcierge\app\Services\FlowControl\PolicyLibrary;
use Modules\WhatsAppVendorConcierge\app\Services\FlowControl\Presentation;
use Modules\WhatsAppVendorConcierge\app\Services\FlowControl\Readiness;
use Modules\WhatsAppVendorConcierge\app\Services\FlowControl\RuntimeSettings;
use Modules\WhatsAppVendorConcierge\app\Services\FlowControl\TestRecipient;
use Modules\WhatsAppVendorConcierge\app\Services\FlowDefinitionValidator;
use Modules\WhatsAppVendorConcierge\app\Services\FlowDiagnostics;
use Modules\WhatsAppVendorConcierge\app\Services\FlowStateMachine;
use Modules\WhatsAppVendorConcierge\app\Services\FlowStorageInvariant;

class FlowControlController extends Controller
{
    public function index(Request $request)
    {
        app(Permissions::class)->assert('view');
        $ready = Schema::hasTable('wa_flow_control_operations');
        app(RuntimeSettings::class)->apply();
        $definitionValid = true;
        try {
            $definition = app(FlowDefinitionValidator::class)->validate();
            $hash = hash_file('sha256', app(FlowDefinitionValidator::class)->path());
        } catch (\Throwable) {
            $definitionValid = false;
            $definition = ['version' => 'unknown', 'data_api_version' => 'unknown', 'screens' => []];
            $hash = str_repeat('0', 64);
        }
        $summary = app(FlowDiagnostics::class)->summary() + ['synchronization' => null];
        $operations = $ready ? DB::table('wa_flow_control_operations')->latest('created_at')->paginate(15, ['id', 'admin_id', 'action', 'target', 'state', 'result', 'created_at', 'updated_at']) : collect();
        $audit = $ready ? DB::table('wa_flow_control_audits')->latest('id')->limit(30)->get() : collect();
        $permissions = collect(Permissions::ALL)->mapWithKeys(fn ($p) => [$p => app(Permissions::class)->allows(auth('admin')->user(), $p)])->all();
        $required = ['test' => 'test_send', 'applications' => 'applications', 'diagnostics' => 'diagnostics', 'policies' => 'policies', 'settings' => 'settings', 'history' => 'diagnostics'];
        if (isset($required[$request->input('tab')])) {
            app(Permissions::class)->assert($required[$request->input('tab')]);
        }
        $nonce = (string) Str::uuid();
        $confirmation = $ready ? app(Confirmation::class)->issue($hash, $nonce) : '';
        $runtime = collect(RuntimeSettings::KEYS)->mapWithKeys(fn ($k) => [$k => config('whatsapp-vendor-flow.'.$k)])->all();
        $policies = Schema::hasTable('legal_policy_versions') ? DB::table('legal_policy_versions')->get() : collect();
        $acceptances = Schema::hasTable('vendor_registration_consents') ? DB::table('vendor_registration_consents')->selectRaw('legal_policy_version_id,COUNT(*) AS count')->groupBy('legal_policy_version_id')->pluck('count', 'legal_policy_version_id') : collect();
        $dates = $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'state' => 'nullable|string|max:40']);
        $analytics = Schema::hasTable('wa_vendor_flow_sessions') ? app(Analytics::class)->cohort($dates['from'] ?? now()->subDays(30)->format('Y-m-d'), $dates['to'] ?? now()->format('Y-m-d'), $dates['state'] ?? null) : null;
        $readiness = $ready && $definitionValid ? app(Readiness::class)->summary() : ['observations' => [], 'checks' => []];

        $diagnostics = $ready ? app(Diagnostics::class)->summary() : [];

        return view('whatsappvendorconcierge::admin.flow_control.index', compact('ready', 'definition', 'summary', 'operations', 'audit', 'permissions', 'nonce', 'hash', 'runtime', 'policies', 'acceptances', 'analytics', 'definitionValid', 'confirmation', 'readiness', 'diagnostics'));
    }

    public function action(Request $request)
    {
        app(RuntimeSettings::class)->apply();
        $data = $request->validate(['action' => 'required|string', 'nonce' => 'required|uuid', 'confirmation' => 'nullable|string|max:40', 'asset_hash' => 'required|string|size:64', 'review_token' => 'required|string|max:4096']);
        abort_unless(isset(Lifecycle::ACTIONS[$data['action']]), 422, 'Unsupported operation.');
        app(Permissions::class)->assert(Lifecycle::ACTIONS[$data['action']]);
        app(Confirmation::class)->verify($data['review_token'], $data['asset_hash'], $data['nonce']);
        abort_unless(hash_equals(hash_file('sha256', app(FlowDefinitionValidator::class)->path()), $data['asset_hash']), 409, 'The reviewed definition changed. Refresh before confirming.');
        if (in_array($data['action'], ['publish', 'deprecate', 'create', 'upload', 'key_configure', 'reconcile'], true)) {
            abort_unless(($data['confirmation'] ?? '') === strtoupper($data['action']), 422, 'Type the operation name to confirm.');
        }
        $id = app(Lifecycle::class)->enqueue(auth('admin')->id(), $data['action'], $data['nonce']);

        return redirect()->route('admin.whatsapp.flows.index', ['tab' => 'history'])->with('flow_notice', 'Operation '.$id.' queued. Publication does not enable dispatch.');
    }

    public function emergency()
    {
        app(Permissions::class)->assert('emergency');
        DB::transaction(function () {
            app(RuntimeSettings::class)->put(['enabled' => false, 'rollout_percent' => 0, 'fallback_to_chat' => true]);
            app(Audit::class)->record(auth('admin')->id(), 'emergency_disable', null, 'succeeded', ['enabled' => false, 'rollout_percent' => 0, 'fallback_to_chat' => true]);
        });

        return back()->with('flow_notice', 'Flow dispatch disabled. Chat fallback remains enabled.');
    }

    public function settings(Request $request)
    {
        app(Permissions::class)->assert('settings');
        app(Permissions::class)->assert('rollout');
        app(RuntimeSettings::class)->apply();
        $d = $request->validate(['enabled' => 'required|boolean', 'rollout_percent' => 'required|integer|min:0|max:100', 'session_minutes' => 'required|integer|min:10|max:1440', 'test_phones' => 'nullable|string|max:600', 'flow_id' => 'nullable|regex:/^[0-9]+$/', 'confirmation' => 'required|in:SAVE SETTINGS', 'live_validation' => 'nullable|in:I VERIFIED LIVE ONBOARDING']);
        $phones = array_values(array_unique(array_filter(array_map('trim', explode(',', $d['test_phones'] ?? '')))));
        abort_if(count($phones) > 20, 422, 'At most 20 approved controlled recipients.');
        foreach ($phones as $phone) {
            abort_unless(preg_match('/^[1-9][0-9]{7,14}$/D', $phone), 422, 'Use international digits without punctuation.');
        }
        $enabled = (bool) $d['enabled'];
        if ($enabled) {
            abort_if(! $phones && (int) $d['rollout_percent'] > 0 && ($d['live_validation'] ?? '') !== 'I VERIFIED LIVE ONBOARDING', 422, 'Public rollout requires explicit confirmation that controlled end-to-end onboarding was verified.');
            app(Readiness::class)->assertActivation($d['flow_id'] ?? '');
        }
        $id = $d['flow_id'] ?? '';
        if ($enabled || $id) {
            $sync = DB::table('wa_vendor_flow_sync')->where('definition_version', config('whatsapp-vendor-flow.definition_version'))->first();
            abort_unless($sync && $sync->status === 'published' && $sync->published_flow_id === $id && $sync->asset_hash === hash_file('sha256', app(FlowDefinitionValidator::class)->path()), 422, 'Select the confirmed published Flow for this exact definition.');
            app(FlowStorageInvariant::class)->assertSafe();
            app(RegistrationPolicyService::class)->manifest('en');
            PrivateRegistrationStorageGuard::assertPrivate(config('whatsapp-vendor-flow.private_root'));
        }
        $safe = ['enabled' => $enabled, 'rollout_percent' => (int) $d['rollout_percent'], 'test_phones' => $phones, 'session_minutes' => (int) $d['session_minutes'], 'flow_id' => $id, 'mode' => $id ? 'published' : 'draft', 'fallback_to_chat' => true];
        DB::transaction(function () use ($safe) {
            app(RuntimeSettings::class)->lock();
            app(RuntimeSettings::class)->apply();
            if ($safe['enabled'] || $safe['flow_id']) {
                $sync = DB::table('wa_vendor_flow_sync')->where('definition_version', config('whatsapp-vendor-flow.definition_version'))->first();
                abort_unless($sync && $sync->status === 'published' && $sync->published_flow_id === $safe['flow_id'] && $sync->asset_hash === hash_file('sha256', app(FlowDefinitionValidator::class)->path()), 409, 'Target changed while reviewing settings. Refresh and review again.');
                if ($safe['enabled']) {
                    app(Readiness::class)->assertActivation($safe['flow_id']);
                }
                app(RegistrationPolicyService::class)->manifest('en');
            }
            $before = collect(RuntimeSettings::KEYS)->mapWithKeys(fn ($k) => [$k => config('whatsapp-vendor-flow.'.$k)])->except('test_phones')->all();
            app(RuntimeSettings::class)->put($safe);
            app(Audit::class)->record(auth('admin')->id(), 'settings_change', $safe['flow_id'] ?: null, 'succeeded', ['before' => $before, 'after' => array_diff_key($safe, ['test_phones' => true]), 'recipient_count' => count($safe['test_phones'])]);
        });

        return back()->with('flow_notice', 'Runtime settings saved. Fallback is preserved.');
    }

    public function permissions(Request $request)
    {
        abort_unless((int) auth('admin')->user()->role_id === 1, 403);
        $d = $request->validate(['role_id' => 'required|integer|min:2|exists:admin_roles,id', 'permissions' => 'array', 'permissions.*' => 'string|in:'.implode(',', Permissions::ALL), 'confirmation' => 'required|in:GRANT PERMISSIONS']);
        DB::transaction(function () use ($d) {
            DB::table('wa_flow_control_permissions')->where('role_id', $d['role_id'])->delete();
            foreach (array_unique($d['permissions'] ?? []) as $permission) {
                DB::table('wa_flow_control_permissions')->insert(['role_id' => $d['role_id'], 'permission' => $permission]);
            }
            app(Audit::class)->record(auth('admin')->id(), 'permission_change', (string) $d['role_id'], 'succeeded', ['permissions' => $d['permissions'] ?? []]);
        });

        return back()->with('flow_notice', 'Role permissions saved. Existing store and conversation permissions are also required.');
    }

    public function report()
    {
        app(Permissions::class)->assert('diagnostics');
        $rows = DB::table('wa_flow_control_operations')->latest('created_at')->limit(50)->get(['id', 'action', 'target', 'state', 'result', 'created_at', 'updated_at']);
        $report = ['environment' => app()->environment(), 'definition_version' => config('whatsapp-vendor-flow.definition_version'), 'asset_hash' => hash_file('sha256', app(FlowDefinitionValidator::class)->path()), 'operations' => $rows, 'flow_status' => app(FlowDiagnostics::class)->summary(), 'diagnostics' => app(Diagnostics::class)->summary()];

        return response()->streamDownload(fn () => print (json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)), 'flow-support-summary.json', ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
    }

    public function policyIntegrity()
    {
        app(Permissions::class)->assert('policies');
        $ok = true;
        try {
            $manifest = app(RegistrationPolicyService::class)->manifest('en');
        } catch (\Throwable) {
            $ok = false;
        }
        app(Audit::class)->record(auth('admin')->id(), 'policy_integrity', null, $ok ? 'succeeded' : 'failed', $ok ? ['locale' => 'en', 'terms_version' => $manifest['terms']['version'], 'privacy_version' => $manifest['privacy']['version'], 'presentation_hash' => $manifest['presentation_hash']] : ['error' => 'archive_or_policy_unavailable']);

        return back()->with('flow_notice', $ok ? 'Both current immutable archives and policy bindings verified.' : 'Policy integrity failed. Registration must remain blocked until repaired.');
    }

    public function publishPolicy(Request $request)
    {
        app(Permissions::class)->assert('policies');
        app(Permissions::class)->assert('publish');
        $d = $request->validate(['kind' => 'required|in:terms,privacy', 'version' => 'required|regex:/^[a-zA-Z0-9_-]{1,100}$/', 'document' => 'required|string|max:4194304', 'confirmation' => 'required|in:PUBLISH POLICY']);
        try {
            app(PolicyLibrary::class)->publish($d['kind'], $d['version'], $d['document'], auth('admin')->id());
        } catch (\LogicException|\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['policy' => $e->getMessage()]);
        }

        return back()->with('flow_notice', 'New immutable policy archive published. Current policy selection and dispatch remain unchanged.');
    }

    public function selectPolicy(Request $request)
    {
        app(Permissions::class)->assert('policies');
        app(Permissions::class)->assert('publish');
        app(Permissions::class)->assert('rollout');
        $d = $request->validate(['terms' => 'required|string|max:100', 'privacy' => 'required|string|max:100', 'confirmation' => 'required|in:SELECT SHARED POLICIES']);
        try {
            app(PolicyLibrary::class)->select($d['terms'], $d['privacy'], auth('admin')->id());
        } catch (\LogicException $e) {
            throw ValidationException::withMessages(['policy' => $e->getMessage()]);
        }

        return back()->with('flow_notice', 'Verified immutable versions selected for shared canonical registration. Flow dispatch disabled for renewed controlled validation.');
    }

    public function testCheck(Request $request)
    {
        app(Permissions::class)->assert('test_send');
        $d = $request->validate(['recipient' => 'required|regex:/^[1-9][0-9]{7,14}$/']);
        $check = app(TestRecipient::class)->check($d['recipient']);
        $request->session()->put('flow_test_recipient', ['number' => $d['recipient'], 'expires' => now()->addMinutes(5)->timestamp]);
        app(Audit::class)->record(auth('admin')->id(), 'test_preflight', null, 'succeeded', array_diff_key($check, ['contact_id' => true, 'conversation_id' => true]));

        return view('whatsappvendorconcierge::admin.flow_control.test', compact('check'));
    }

    public function testSend(Request $request)
    {
        app(Permissions::class)->assert('test_send');
        $request->validate(['confirmation' => 'required|in:SEND TEST FLOW']);
        $binding = $request->session()->pull('flow_test_recipient');
        abort_unless($binding && $binding['expires'] > now()->timestamp, 409, 'Preflight expired; check again.');
        $service = app(TestRecipient::class);
        $result = $service->send($binding['number'], auth('admin')->id());

        return redirect()->route('admin.whatsapp.flows.index', ['tab' => 'history'])->with('flow_notice', $result);
    }

    public function applications(Request $request)
    {
        app(Permissions::class)->assert('applications');
        $filters = array_merge(array_keys(FlowStateMachine::EDGES), ['expired', 'active', 'human_handoff', 'pending_approval', 'registered', 'failed']);
        $state = $request->validate(['state' => ['nullable', Rule::in($filters)]])['state'] ?? '';
        $query = VendorFlowSession::query();
        if ($state === 'active') {
            $query->whereNull('consumed_at')->where('expires_at', '>', now())->whereNotIn('state', ['failed_terminal']);
        } elseif ($state === 'human_handoff') {
            $query->whereIn('onboarding_session_id', WhatsAppConversation::where('state', 'human_handoff')->select('onboarding_session_id'));
        } elseif ($state === 'pending_approval') {
            $query->whereIn('vendor_id', DB::table('vendors')->whereNull('status')->select('id'));
        } elseif ($state === 'registered') {
            $query->whereNotNull('consumed_at');
        } elseif ($state === 'failed') {
            $query->whereIn('state', ['failed_recoverable', 'failed_terminal']);
        } elseif ($state === 'expired') {
            $query->whereNull('consumed_at')->where('expires_at', '<', now());
        } elseif ($state) {
            $query->where('state', $state);
        }
        $sessions = $query->latest('id')->paginate(25)->withQueryString();

        return view('whatsappvendorconcierge::admin.flow_control.applications', compact('sessions', 'state'));
    }

    public function application(int $id)
    {
        app(Permissions::class)->assert('applications');
        $session = VendorFlowSession::findOrFail($id);
        $events = DB::table('wa_vendor_flow_events')->where('flow_session_id', $id)->orderBy('id')->get(['event', 'screen', 'created_at']);
        $media = DB::table('wa_vendor_flow_media')->where('flow_session_id', $id)->get(['role', 'state', 'mime', 'bytes']);
        $conversation = WhatsAppConversation::where('onboarding_session_id', $session->onboarding_session_id)->latest('id')->first();
        $fields = array_keys($session->draft ?? []);

        return view('whatsappvendorconcierge::admin.flow_control.application', compact('session', 'events', 'media', 'conversation', 'fields'));
    }

    public function presentation(Request $request)
    {
        app(Permissions::class)->assert('drafts');
        $d = $request->validate(['changes' => 'required|array|max:100', 'changes.*.path' => 'required|string|max:200', 'changes.*.text' => 'required|string|max:80', 'asset_hash' => 'required|string|size:64', 'confirmation' => 'required|in:SAVE REVISION']);
        app(RuntimeSettings::class)->apply();
        abort_unless(hash_equals(hash_file('sha256', app(FlowDefinitionValidator::class)->path()), $d['asset_hash']), 409, 'Definition changed; review again.');
        $version = app(Presentation::class)->save(collect($d['changes'])->pluck('text', 'path')->all(), auth('admin')->id());

        return back()->with('flow_notice', 'Immutable presentation revision '.$version.' saved. Current dispatch and published pointer are unchanged.');
    }

    public function selectRevision(Request $request)
    {
        app(Permissions::class)->assert('drafts');
        app(Permissions::class)->assert('rollout');
        $d = $request->validate(['version' => 'required|string|max:80', 'confirmation' => 'required|in:SELECT REVISION']);
        app(Presentation::class)->select($d['version'], auth('admin')->id());

        return back()->with('flow_notice', 'Reviewed definition selected for a new draft. Dispatch is disabled and previous published synchronization records are retained.');
    }

    public function recover(Request $request, int $id)
    {
        app(Permissions::class)->assert('recovery');
        $d = $request->validate(['action' => 'required|in:expire,assign_human,review', 'confirmation' => 'required|in:RECOVER']);
        try {
            app(ApplicationRecovery::class)->perform($id, $d['action'], auth('admin')->id());
        } catch (\Throwable $error) {
            app(Audit::class)->record(auth('admin')->id(), 'application_'.$d['action'], (string) $id, 'failed', ['error' => 'recovery_not_applied']);
            throw $error;
        }

        return back()->with('flow_notice', 'Recovery recorded. No registration validation was bypassed.');
    }

    public function registrationRecovery(Request $request, int $id)
    {
        app(Permissions::class)->assert('recovery');
        $d = $request->validate(['action' => 'required|in:retry_processing,credential_resend', 'nonce' => 'required|uuid', 'confirmation' => 'required|in:CONFIRM RECOVERY']);
        VendorFlowSession::findOrFail($id);
        app(Lifecycle::class)->enqueue(auth('admin')->id(), $d['action'], $d['nonce'], $id);

        return back()->with('flow_notice', 'Specific registration recovery queued. Inspect operation history and delivery; no vendor will be recreated.');
    }

    public function retryOperation(Request $request, string $id)
    {
        $request->validate(['confirmation' => 'required|in:RETRY']);
        app(Permissions::class)->assert('diagnostics');
        DB::transaction(function () use ($id) {
            $op = DB::table('wa_flow_control_operations')->where('id', $id)->lockForUpdate()->first();
            abort_unless($op, 404);
            app(Permissions::class)->assert(Lifecycle::ACTIONS[$op->action]);
            abort_unless(in_array($op->action, ['inspect', 'compare', 'health', 'local', 'remote', 'key_check', 'ping', 'subscriptions', 'reconcile_preview'], true), 409, 'Mutations require a fresh reviewed workflow; inspect remote state first.');
            abort_unless($op->state === 'failed' || (in_array($op->state, ['queued', 'running'], true) && $op->updated_at < now()->subMinutes(10)->toDateTimeString()), 409, 'This observation is not eligible for retry.');
            DB::table('wa_flow_control_operations')->where('id', $id)->update(['state' => 'queued', 'result' => null, 'updated_at' => now()]);
            app(Audit::class)->record(auth('admin')->id(), 'retry_observation', $op->target, 'queued', ['previous_state' => $op->state], $id);
            RunFlowControlOperation::dispatch($id);
        });

        return back()->with('flow_notice', 'Specific read-only observation requeued. Original snapshot and permissions will be checked again.');
    }

    public function limits(Request $request)
    {
        app(Permissions::class)->assert('settings');
        app(Permissions::class)->assert('recovery');
        $d = $request->validate(['max_image_bytes' => 'required|integer|min:65536|max:2097152', 'max_document_bytes' => 'required|integer|min:65536|max:2097152', 'max_dimension' => 'required|integer|min:256|max:6000', 'max_pixels' => 'required|integer|min:65536|max:16000000', 'cleanup_schedule' => 'required|in:disabled,daily,hourly', 'abandonment_hours' => 'required|integer|min:1|max:720', 'analytics_retention_days' => 'nullable|integer|min:1|max:3650', 'confirmation' => 'required|in:SAVE LIMITS AND CLEANUP']);
        $safe = collect($d)->except('confirmation')->map(fn ($v, $k) => $k === 'cleanup_schedule' ? $v : ($v === null ? null : (int) $v))->all();
        DB::transaction(function () use ($safe) {
            app(RuntimeSettings::class)->put($safe);
            app(Audit::class)->record(auth('admin')->id(), 'limits_cleanup_change', null, 'succeeded', $safe);
        });

        return back()->with('flow_notice', 'Reviewed constraints and cleanup policy saved. Consent evidence, policy archives and administrative audits are never removed by analytics retention.');
    }

    public function deprecate(Request $request)
    {
        app(Permissions::class)->assert('publish');
        $d = $request->validate(['flow_id' => 'required|regex:/^[0-9]{1,30}$/D', 'nonce' => 'required|uuid', 'asset_hash' => 'required|string|size:64', 'review_token' => 'required|string|max:4096', 'confirmation' => 'required|string|max:80']);
        app(Confirmation::class)->verify($d['review_token'], $d['asset_hash'], $d['nonce']);
        abort_unless($d['confirmation'] === 'DEPRECATE '.$d['flow_id'], 422, 'Type DEPRECATE followed by the reviewed obsolete Flow ID.');
        abort_unless(DB::table('wa_vendor_flow_sync')->where('published_flow_id', $d['flow_id'])->exists(), 422, 'Select a recorded published Flow.');
        app(Lifecycle::class)->enqueue(auth('admin')->id(), 'deprecate', $d['nonce'], null, $d['flow_id']);

        return redirect()->route('admin.whatsapp.flows.index', ['tab' => 'history'])->with('flow_notice', 'Obsolete Flow retirement queued. Active and last working pointers remain protected.');
    }
}
