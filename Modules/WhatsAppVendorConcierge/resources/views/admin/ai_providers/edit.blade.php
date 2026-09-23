@extends('layouts.admin.app')

@section('title', translate('Edit AI Provider Connection'))

@section('content')
<div class="content container-fluid">
    <div class="page-header">
        <h1 class="page-header-title">
            <span class="page-header-icon"><i class="tio-edit"></i></span>
            <span>{{translate('Edit Connection')}}: {{$connection->name}}</span>
        </h1>
        <p class="text-muted">{{translate('Update account credentials, endpoints, or budget ceilings.')}}</p>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <form action="{{route('admin.whatsapp.ai-providers.update', $connection->id)}}" method="POST">
                        @csrf
                        @method('PUT')

                        <div class="form-group mb-3">
                            <label class="input-label font-weight-bold">{{translate('Provider Platform')}}</label>
                            <input type="text" class="form-control" value="{{$connection->definition->name ?? 'Custom'}}" disabled>
                        </div>

                        <div class="form-group mb-3">
                            <label class="input-label font-weight-bold" for="name">{{translate('Account Label / Name')}} <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="name" class="form-control" value="{{old('name', $connection->name)}}" required>
                        </div>

                        <div class="form-group mb-3">
                            <label class="input-label font-weight-bold" for="api_key">{{translate('Update API Key (Leave blank to keep existing key)')}}</label>
                            <div class="input-group">
                                <input type="password" name="api_key" id="api_key" class="form-control" placeholder="••••••••••••••••••••••••" autocomplete="off">
                                <div class="input-group-append">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('api_key')">
                                        <i class="tio-hidden-outlined" id="api_key_icon"></i>
                                    </button>
                                </div>
                            </div>
                            <small class="text-muted">{{translate('The existing key is safely stored encrypted. Enter a new key only if rotating.')}}</small>
                        </div>

                        <div class="form-group mb-3">
                            <label class="input-label" for="base_url_override">{{translate('Custom Base URL Endpoint (Optional)')}}</label>
                            <input type="url" name="base_url_override" id="base_url_override" class="form-control" value="{{old('base_url_override', $connection->base_url_override)}}">
                        </div>

                        <div class="form-group mb-3">
                            <label class="input-label font-weight-bold" for="selection_mode">{{translate('Model Selection Strategy')}} <span class="text-danger">*</span></label>
                            <select name="selection_mode" id="selection_mode" class="form-control" required>
                                <option value="all_compatible" {{old('selection_mode', $connection->selection_mode) === 'all_compatible' ? 'selected' : ''}}>
                                    {{translate('Enable All Compatible Models')}}
                                </option>
                                <option value="auto_include_free" {{old('selection_mode', $connection->selection_mode) === 'auto_include_free' ? 'selected' : ''}}>
                                    {{translate('Auto-include & Prioritise Free Tier Models Only (Zero Cost)')}}
                                </option>
                                <option value="manual" {{old('selection_mode', $connection->selection_mode) === 'manual' ? 'selected' : ''}}>
                                    {{translate('Manual Selection')}}
                                </option>
                            </select>
                        </div>

                        <div class="row">
                            <div class="col-md-6 form-group mb-3">
                                <label class="input-label" for="daily_budget_usd">{{translate('Daily Cost Cap (USD)')}}</label>
                                <input type="number" step="0.01" min="0" name="daily_budget_usd" id="daily_budget_usd" class="form-control" value="{{old('daily_budget_usd', $connection->daily_budget_usd)}}">
                            </div>
                            <div class="col-md-6 form-group mb-3">
                                <label class="input-label" for="monthly_budget_usd">{{translate('Monthly Cost Cap (USD)')}}</label>
                                <input type="number" step="0.01" min="0" name="monthly_budget_usd" id="monthly_budget_usd" class="form-control" value="{{old('monthly_budget_usd', $connection->monthly_budget_usd)}}">
                            </div>
                        </div>

                        <div class="d-flex justify-content-end gap-3 mt-4">
                            <a href="{{route('admin.whatsapp.ai-providers.index')}}" class="btn btn-secondary mr-2">{{translate('Cancel')}}</a>
                            <button type="submit" class="btn btn-primary"><i class="tio-save"></i> {{translate('Update Settings')}}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
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
