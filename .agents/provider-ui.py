from pathlib import Path
r=Path('Modules/WhatsAppVendorConcierge')
p=r/'app/Providers/WhatsAppVendorConciergeServiceProvider.php';s=p.read_text().replace('        $this->commands([','        $this->commands([\n            \\Modules\\WhatsAppVendorConcierge\\app\\Console\\Commands\\AiTestModelsCommand::class,');p.write_text(s)
p=r/'resources/views/admin/ai_providers/create.blade.php';s=p.read_text().replace('data-base-url="{{$def->default_base_url}}"','data-base-url="{{$def->default_base_url}}" data-slug="{{$def->slug}}"').replace('<label class="input-label" for="account_id">','<label class="input-label" for="account_id">');s=s.replace('    const baseUrl = selected.getAttribute', '''    const slug = selected.getAttribute('data-slug');
    const account = document.getElementById('account_id');
    account.closest('.form-group').hidden = slug !== 'cloudflare_workers_ai';
    account.disabled = slug !== 'cloudflare_workers_ai';
    account.required = slug === 'cloudflare_workers_ai';
    document.querySelector('[name="free_plan_confirmed"]').disabled = slug !== 'cloudflare_workers_ai';
    const customEndpoint = document.getElementById('base_url_override');
    customEndpoint.disabled = ['cerebras', 'cloudflare_workers_ai', 'openrouter_free'].includes(slug);
    if (customEndpoint.disabled) customEndpoint.value = '';
    if (slug === 'cerebras') document.getElementById('selection_mode').value = 'manual';
    const baseUrl = selected.getAttribute''');s=s.replace('</script>','handleProviderChange(document.getElementById("definition_id"));\n</script>');p.write_text(s)
p=r/'resources/views/admin/ai_providers/edit.blade.php';s=p.read_text();a=s.index('                        <div class="form-group mb-3">\n                            <label class="input-label" for="account_id">');b=s.index('                        <div class="form-group mb-3">',a+40);s=s[:a]+"                        @if($connection->definition->slug === 'cloudflare_workers_ai')\n"+s[a:b]+"                        @endif\n"+s[b:];p.write_text(s)
