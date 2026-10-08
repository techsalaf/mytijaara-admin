<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\FlowControl;

use App\Models\LegalPolicyVersion;
use App\Services\PrivateRegistrationStorageGuard;
use App\Services\RegistrationPolicyService;
use Illuminate\Support\Facades\DB;

class PolicyLibrary
{
    public function publish(string $kind, string $version, string $html, int $admin): void
    {
        if (! in_array($kind, ['terms', 'privacy'], true) || ! preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $version) || ! mb_check_encoding($html, 'UTF-8') || strlen($html) > 4194304 || trim(strip_tags($html)) === '') {
            throw new Failure('A valid version and approved UTF-8 policy document are required.');
        }
        $disk = (string) config('registration-policies.archive_disk');
        if ($disk !== 'registration_private') {
            throw new Failure('Policy publication requires the reviewed local private archive disk.');
        }
        $root = (string) config('filesystems.disks.registration_private.root');
        PrivateRegistrationStorageGuard::assertPrivate($root);
        $hash = hash('sha256', $html);
        DB::transaction(function () use ($kind, $version, $html, $admin, $root, $hash) {
            app(RuntimeSettings::class)->lock();
            if ($row = LegalPolicyVersion::where('version', $version)->first()) {
                if ($row->policy === $kind && hash_equals($row->content_hash, $hash)) {
                    return;
                }
                throw new Failure('Version already exists. Published wording cannot be changed.');
            }
            $directory = $root.'/policy-documents';
            if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
                throw new Failure('Private policy archive unavailable.');
            }
            $path = $directory.'/'.$version.'.html';
            if (! is_file($path)) {
                $file = fopen($path, 'x');
                if (! $file) {
                    throw new Failure('Immutable archive object cannot be created.');
                }
                try {
                    if (fwrite($file, $html) !== strlen($html)) {
                        throw new Failure('Policy archive write incomplete.');
                    }
                } finally {
                    fclose($file);
                }
                chmod($path, 0440);
            }
            if (! hash_equals($hash, hash_file('sha256', $path))) {
                throw new Failure('Existing archive content differs. Choose a new version.');
            }
            $url = rtrim((string) config('app.url'), '/').route('whatsapp.policy-document', ['version' => $version], false);
            if (! str_starts_with($url, 'https://')) {
                throw new Failure('Policy archive must have a trusted HTTPS origin.');
            }
            LegalPolicyVersion::create(['policy' => $kind, 'version' => $version, 'locale' => 'en', 'content_hash' => $hash, 'document_url' => $url, 'storage_object' => 'policy-documents/'.$version.'.html', 'effective_at' => now('UTC'), 'created_at' => now('UTC')]);
            app(Audit::class)->record($admin, 'policy_publication', $version, 'succeeded', ['kind' => $kind, 'locale' => 'en', 'content_hash' => $hash, 'current_selection_changed' => false]);
        });
    }

    public function select(string $terms, string $privacy, int $admin): void
    {
        DB::transaction(function () use ($terms, $privacy, $admin) {
            app(RuntimeSettings::class)->lock();
            if (DB::table('wa_vendor_flow_sessions')->whereNull('consumed_at')->where('expires_at', '>', now())->exists()) {
                throw new Failure('Active Flow drafts must finish or expire before policy selection changes.');
            }
            foreach (['terms' => $terms, 'privacy' => $privacy] as $kind => $version) {
                if (! LegalPolicyVersion::where('version', $version)->where('policy', $kind)->where('locale', 'en')->where('effective_at', '<=', now('UTC'))->whereNull('retired_at')->exists()) {
                    throw new Failure('Choose an effective immutable English policy of the correct kind.');
                }
            }
            $service = app(RegistrationPolicyService::class);
            $before = config('registration-policies.current.en');
            config(['registration-policies.current.en' => ['terms' => $terms, 'privacy' => $privacy]]);
            try {
                $manifest = $service->manifest('en');
            } catch (\Throwable $e) {
                config(['registration-policies.current.en' => $before]);
                throw new Failure('Selected policy archives failed integrity verification.');
            }
            app(RuntimeSettings::class)->put(['current_terms_version' => $terms, 'current_privacy_version' => $privacy, 'enabled' => false, 'rollout_percent' => 0, 'fallback_to_chat' => true]);
            app(Audit::class)->record($admin, 'policy_selection', null, 'succeeded', ['before' => $before, 'after' => ['terms' => $terms, 'privacy' => $privacy], 'presentation_hash' => $manifest['presentation_hash'], 'dispatch_enabled' => false]);
        });
    }
}
