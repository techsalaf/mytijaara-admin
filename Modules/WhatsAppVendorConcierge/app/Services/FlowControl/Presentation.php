<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\FlowControl;

use App\Services\PrivateRegistrationStorageGuard;
use Illuminate\Support\Facades\DB;
use Modules\WhatsAppVendorConcierge\app\Services\FlowDefinitionValidator;

class Presentation
{
    public function editable(array $definition): array
    {
        $out = [];
        foreach ($definition['screens'] as $i => $screen) {
            $out['screens.'.$i.'.title'] = $screen['title'];
            $this->nodes($screen['layout']['children'], 'screens.'.$i.'.layout.children', $out);
        }

        return $out;
    }

    private function nodes(array $nodes, string $path, array &$out): void
    {
        foreach ($nodes as $i => $node) {
            $p = $path.'.'.$i;
            if (($node['type'] ?? '') === 'If') {
                foreach (['then', 'else'] as $branch) {
                    $this->nodes($node[$branch] ?? [], $p.'.'.$branch, $out);
                }
            }
            foreach (['helper-text', 'label'] as $property) {
                // Legal links/consent, expressions, instructions and machine bindings stay locked.
                if (isset($node[$property]) && is_string($node[$property]) && ! str_contains($node[$property], '${') && in_array($node['type'], ['TextInput', 'TextArea', 'Dropdown', 'CheckboxGroup', 'PhotoPicker', 'DocumentPicker', 'Footer'], true)) {
                    $out[$p.'.'.$property] = $node[$property];
                }
            }
        }
    }

    public function revised(array $base, array $changes): array
    {
        $allowed = $this->editable($base);
        foreach ($changes as $path => $text) {
            $limit = str_ends_with($path, '.title') ? 30 : (str_ends_with($path, '.label') ? 20 : 80);
            if (! array_key_exists($path, $allowed) || ! is_string($text) || trim($text) === '' || mb_strlen($text) > $limit || str_contains($text, '${') || preg_match('/[<>\x00-\x1f]|https?:\/\/|\S+@\S+/', $text)) {
                throw new Failure('Only bounded, plain presentation text may change.');
            }
            foreach (['whatsapp-vendor-concierge.api.access_token', 'whatsapp-vendor-concierge.api.app_secret'] as $key) {
                $secret = (string) config($key);
                if (strlen($secret) >= 8 && str_contains($text, $secret)) {
                    throw new Failure('Credentials cannot be presentation text.');
                }
            }
            data_set($base, $path, $text);
        }
        app(FlowDefinitionValidator::class)->validate($base);

        return $base;
    }

    public function save(array $changes, int $admin): string
    {
        app(RuntimeSettings::class)->apply();
        $validator = app(FlowDefinitionValidator::class);
        $base = $validator->validate();
        $revised = $this->revised($base, $changes);
        $bytes = json_encode($revised, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $hash = hash('sha256', $bytes);
        $version = 'vendor-onboarding-ui-'.substr($hash, 0, 24);
        $root = (string) config('whatsapp-vendor-flow.private_root');
        PrivateRegistrationStorageGuard::assertPrivate($root);
        $directory = $root.'/definitions';
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            throw new Failure('Private definition archive unavailable.');
        }
        $path = $directory.'/'.$version.'.json';
        if (! is_file($path)) {
            $file = fopen($path, 'x');
            if (! $file) {
                throw new Failure('Definition archive cannot be created.');
            }
            try {
                if (fwrite($file, $bytes) !== strlen($bytes)) {
                    throw new Failure('Incomplete definition archive.');
                }
            } finally {
                fclose($file);
            }
            chmod($path, 0440);
        }
        if (! hash_equals($hash, hash_file('sha256', $path))) {
            throw new Failure('Definition archive integrity mismatch.');
        }
        DB::transaction(function () use ($version, $hash, $changes, $admin) {
            // No activation occurs. Current published pointer and live sessions are untouched.
            DB::table('wa_flow_definition_revisions')->insertOrIgnore(['version' => $version, 'asset_hash' => $hash, 'admin_id' => $admin, 'presentation_changes' => json_encode($changes), 'created_at' => now('UTC')]);
            app(Audit::class)->record($admin, 'presentation_revision', $version, 'succeeded', ['asset_hash' => $hash, 'changed_paths' => array_keys($changes)]);
        });

        return $version;
    }

    public function select(string $version, int $admin): void
    {
        abort_unless($version === 'vendor-onboarding-2026-10-07.1' || DB::table('wa_flow_definition_revisions')->where('version', $version)->exists(), 422, 'Select a reviewed revision.');
        DB::transaction(function () use ($version, $admin) {
            app(RuntimeSettings::class)->lock();
            abort_if(DB::table('wa_vendor_flow_sessions')->whereNull('consumed_at')->where('expires_at', '>', now())->exists(), 409, 'Active sessions must finish or expire before selecting a new definition.');
            app(RuntimeSettings::class)->put(['enabled' => false, 'rollout_percent' => 0, 'fallback_to_chat' => true, 'flow_id' => '', 'mode' => 'draft', 'definition_version' => $version]);
            app(FlowDefinitionValidator::class)->validate();
            app(Audit::class)->record($admin, 'definition_select', $version, 'succeeded', ['dispatch_enabled' => false, 'previous_published_sync_retained' => true]);
        });
    }
}
