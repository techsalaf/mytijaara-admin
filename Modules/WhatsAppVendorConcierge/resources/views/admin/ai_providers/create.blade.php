@extends('layouts.admin.app')

@section('title', translate('Connect AI Provider'))

@section('content')
<div class="content container-fluid">
    <div class="page-header">
        <h1 class="page-header-title">
            <span class="page-header-icon"><i class="tio-add"></i></span>
            <span>{{translate('Connect AI Provider Account')}}</span>
        </h1>
        <p class="text-muted">{{translate('Credentials are encrypted and never shown back in plaintext or exposed to LLM prompts.')}}</p>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <form action="{{route('admin.whatsapp.ai-providers.store')}}" method="POST">
                        @csrf

                        <div class="form-group mb-3">
                            <label class="input-label font-weight-bold" for="definition_id">{{translate('Provider Platform')}} <span class="text-danger">*</span></label>
                            <select name="definition_id" id="definition_id" class="form-control" required onchange="handleProviderChange(this)">
                                <option value="">-- {{translate('Select Provider')}} --</option>
                                @foreach($definitions as $def)
                                    <option value="{{$def->id}}"
                                        data-base-url="{{$def->default_base_url}}"
                                        {{(old('definition_id', $selectedDefinition?->id) == $def->id) ? 'selected' : ''}}>
                                        {{$def->name}}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-group mb-3">
                            <label class="input-label font-weight-bold" for="name">{{translate('Account Label / Name')}} <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="name" class="form-control" placeholder="e.g. Primary Groq Cloud Key" value="{{old('name', $selectedDefinition ? $selectedDefinition->name . ' Account' : '')}}" required>
                            <small class="text-muted">{{translate('Friendly name to distinguish multiple accounts for the same provider.')}}</small>
                        </div>

                        <div class="form-group mb-3">
                            <label class="input-label font-weight-bold" for="api_key">{{translate('API Key / Secret Token')}} <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="password" name="api_key" id="api_key" class="form-control" placeholder="Enter API Key" required autocomplete="off">
                                <div class="input-group-append">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('api_key')">
                                        <i class="tio-hidden-outlined" id="api_key_icon"></i>
                                    </button>
                                </div>
                            </div>
                            <small class="text-muted">{{translate('Stored with AES-256 encryption. Masked once saved.')}}</small>
                        </div>

                        <div class="form-group mb-3">
                            <label class="input-label" for="base_url_override">{{translate('Custom Base URL Endpoint (Optional)')}}</label>
                            <input type="url" name="base_url_override" id="base_url_override" class="form-control" placeholder="{{$selectedDefinition?->default_base_url ?? 'https://api.openai.com/v1'}}" value="{{old('base_url_override')}}">
                            <small class="text-muted">{{translate('Leave empty to use the provider default endpoint.')}}</small>
                        </div>

                        <div class="form-group mb-3">
                            <label class="input-label font-weight-bold" for="selection_mode">{{translate('Model Selection Strategy')}} <span class="text-danger">*</span></label>
                            <select name="selection_mode" id="selection_mode" class="form-control" required>
                                <option value="all_compatible" {{old('selection_mode') === 'all_compatible' ? 'selected' : ''}}>
                                    {{translate('Enable All Compatible Models (Default)')}}
                                </option>
                                <option value="auto_include_free" {{old('selection_mode') === 'auto_include_free' ? 'selected' : ''}}>
                                    {{translate('Auto-include & Prioritise Free Tier Models Only (Zero Cost)')}}
                                </option>
                                <option value="manual" {{old('selection_mode') === 'manual' ? 'selected' : ''}}>
                                    {{translate('Manual Selection (Select models individually after discovery)')}}
                                </option>
                            </select>
                        </div>

                        <div class="row">
                            <div class="col-md-6 form-group mb-3">
                                <label class="input-label" for="daily_budget_usd">{{translate('Daily Cost Cap (USD)')}}</label>
                                <input type="number" step="0.01" min="0" name="daily_budget_usd" id="daily_budget_usd" class="form-control" placeholder="e.g. 5.00" value="{{old('daily_budget_usd')}}">
                                <small class="text-muted">{{translate('Automatic circuit breaker stops routing if daily spend hits this ceiling.')}}</small>
                            </div>
                            <div class="col-md-6 form-group mb-3">
                                <label class="input-label" for="monthly_budget_usd">{{translate('Monthly Cost Cap (USD)')}}</label>
                                <input type="number" step="0.01" min="0" name="monthly_budget_usd" id="monthly_budget_usd" class="form-control" placeholder="e.g. 50.00" value="{{old('monthly_budget_usd')}}">
                            </div>
                        </div>

                        <div class="d-flex justify-content-end gap-3 mt-4">
                            <a href="{{route('admin.whatsapp.ai-providers.index')}}" class="btn btn-secondary mr-2">{{translate('Cancel')}}</a>
                            <button type="submit" class="btn btn-primary"><i class="tio-save"></i> {{translate('Save & Discover Models')}}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function handleProviderChange(select) {
    const selected = select.options[select.selectedIndex];
    const baseUrl = selected.getAttribute('data-base-url');
    if (baseUrl) {
        document.getElementById('base_url_override').placeholder = baseUrl;
    }
    const nameInput = document.getElementById('name');
    if (!nameInput.value || nameInput.value.includes('Account')) {
        nameInput.value = selected.text.trim() + ' Account';
    }
}

function togglePasswordVisibility(fieldId) {
    const field = document.getElementById(fieldId);
    const icon = document.getElementById(fieldId + '_icon');
    if (field.type === 'password') {
        field.type = 'text';
        icon.className = 'tio-visible-outlined';
    } else {
        field.type = 'password';
        icon.className = 'tio-hidden-outlined';
    }
}
</script>
@endsection
