from pathlib import Path
r=Path('Modules/WhatsAppVendorConcierge')
p=r/'app/Http/Controllers/Admin/AiProviderController.php';s=p.read_text();s=s.replace("        $connection = AiProviderConnection::create([", "        $definition = AiProviderDefinition::findOrFail($validated['definition_id']);\n        $extraCredentials = $this->providerCredentials($request, $definition->slug);\n\n        $connection = AiProviderConnection::create([");s=s.replace("'api_key' => trim($validated['api_key']),\n            ],", "'api_key' => trim($validated['api_key']),\n            ] + $extraCredentials,",1);s=s.replace("'selection_mode' => $validated['selection_mode'],", "'selection_mode' => $definition->slug === 'cerebras' ? 'manual' : $validated['selection_mode'],",1)
s=s.replace("        $updates = [", "        $extraCredentials = $this->providerCredentials($request, $aiProvider->definition->slug, $aiProvider->credentials);\n        $updates = [",1);s=s.replace("$updates['credentials'] = [\n                'api_key' => trim($validated['api_key']),\n            ];", "$updates['credentials'] = array_merge($aiProvider->credentials ?? [], $extraCredentials, [\n                'api_key' => trim($validated['api_key']),\n            ]);")
s=s.replace("        $aiProvider->update($updates);", "        $updates['credentials'] ??= array_merge($aiProvider->credentials ?? [], $extraCredentials);\n        if ($updates['credentials'] !== $aiProvider->credentials) {\n            $updates['status'] = 'unverified';\n        }\n        $aiProvider->update($updates);",1)
idx=s.index('    /**',s.index('class AiProviderController'))
s=s[:idx]+r'''    private function providerCredentials(Request $request, string $slug, array $existing = []): array
    {
        if (in_array($slug, ['cerebras', 'cloudflare_workers_ai', 'openrouter_free'], true)) {
            $request->validate(['base_url_override' => 'prohibited']);
        }
        if ($slug !== 'cloudflare_workers_ai') return [];
        $request->validate([
            'account_id' => ['required', 'regex:/\A[a-f0-9]{32}\z/i'],
            'free_plan_confirmed' => 'accepted',
        ]);
        return ['account_id' => trim($request->input('account_id')), 'free_plan_confirmed' => true];
    }

'''+s[idx:];p.write_text(s)
# Label discovery accurately and disable stale models only for the new strict integrations.
p=r/'app/Services/ModelDiscoveryService.php';s=p.read_text();s=s.replace("        // 2. If API discovery", "        $apiDiscovered = !empty($discovered);\n\n        // 2. If API discovery");s=s.replace("        $connection->update([\n            'last_tested_at' => now(),\n            'status' => 'healthy',\n            'last_error' => null,\n        ]);",r'''        if (in_array($definition?->slug, ['cerebras', 'cloudflare_workers_ai', 'openrouter_free'], true)) {
            $connection->models()->whereNotIn('model_id', array_column($discovered, 'id'))
                ->update(['is_enabled' => false]);
        }
        $connection->update([
            'status' => $apiDiscovered ? 'models_discovered' : 'unverified',
            'last_error' => $apiDiscovered ? null : 'Live discovery did not verify any models. Check credentials and provider availability.',
        ]);''');p.write_text(s)
# Shared clear setup guidance; no translated keys written into core locale files.
(r/'resources/views/admin/ai_providers/provider-help.blade.php').write_text('''<div class="alert alert-info" role="note">
    <strong>Free provider setup</strong>
    <ul class="mb-0 pl-3">
        <li><strong>OpenRouter free:</strong> only zero-price, tool-capable free models are discovered; paid model IDs are blocked.</li>
        <li><strong>Cloudflare:</strong> use a Workers Free account to avoid paid overages. Enter its account ID and a token with Workers AI Read/Write permissions. The daily allowance is shared across your account.</li>
        <li><strong>Cerebras:</strong> currently a limited trial, not a permanent free tier. New connections start with manual model selection. Enable a model only after checking your trial balance or authorizing paid usage.</li>
    </ul>
    <small>These three providers use official endpoints; leave Custom Base URL empty. Save/discover verifies model availability, not successful chat or tool execution. Run the model test before routing vendor conversations. Zero-dollar local budgets disable routing; they do not enforce a provider's free plan.</small>
</div>
''',encoding='utf8')
for name in ['create','edit']:
 p=r/f'resources/views/admin/ai_providers/{name}.blade.php';s=p.read_text();s=s.replace('                        @csrf',"                        @csrf\n                        @include('whatsapp-vendor-concierge::admin.ai_providers.provider-help')");needle='                        <div class="form-group mb-3">\n                            <label class="input-label" for="base_url_override">';field='''                        <div class="form-group mb-3">
                            <label class="input-label" for="account_id">Cloudflare account ID (Cloudflare only)</label>
                            <input id="account_id" name="account_id" class="form-control" maxlength="32" pattern="[a-fA-F0-9]{32}" value="VALUE" autocomplete="off">
                            <label class="mt-2"><input type="checkbox" name="free_plan_confirmed" value="1" CHECKED> I confirm this Cloudflare account uses Workers Free; I will disable this connection before upgrading to a paid plan.</label>
                        </div>

''';field=field.replace('VALUE',"{{ old('account_id'"+( ", $connection->credentials['account_id'] ?? ''" if name=='edit' else '')+") }}").replace('CHECKED',"{{ old('free_plan_confirmed'"+(", $connection->credentials['free_plan_confirmed'] ?? false" if name=='edit' else '')+") ? 'checked' : '' }}")
 assert needle in s,name;s=s.replace(needle,field+needle).replace('Auto-include & Prioritise Free Tier Models Only (Zero Cost)','Prioritise models with free allowances (provider limits apply)');p.write_text(s)
