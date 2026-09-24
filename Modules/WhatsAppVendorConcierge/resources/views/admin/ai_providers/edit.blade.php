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
        <div class="col-lg-7">
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header">
                    <h5 class="card-title mb-0">Connection Details</h5>
                </div>
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
                            <a href="{{route('admin.whatsapp.ai-dashboard.index')}}" class="btn btn-secondary mr-2">{{translate('Back to Dashboard')}}</a>
                            <button type="submit" class="btn btn-primary"><i class="tio-save"></i> {{translate('Update Settings')}}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header bg-light">
                    <h5 class="card-title mb-0"><i class="tio-settings"></i> Lifecycle & Diagnostics</h5>
                </div>
                <div class="card-body">
                    <ul class="list-group list-group-flush mb-4">
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            State
                            <span class="badge badge-soft-{{ $connection->isAvailable() ? 'success' : 'danger' }}">{{ strtoupper($connection->status) }}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Last Tested
                            <span class="text-muted">{{ $connection->last_tested_at ? $connection->last_tested_at->diffForHumans() : 'Never' }}</span>
                        </li>
                    </ul>

                    <h6>Run Diagnostic Tests</h6>
                    <div class="d-grid gap-2">
                        <button class="btn btn-outline-primary btn-block text-left mb-2" onclick="runDiagnostic('credentials')">
                            <i class="tio-key"></i> 1. Test Authentication
                        </button>
                        <button class="btn btn-outline-primary btn-block text-left mb-2" onclick="runDiagnostic('discovery')">
                            <i class="tio-search"></i> 2. Test Model Discovery
                        </button>
                        
                        <div class="input-group mb-2">
                            <select class="form-control" id="diag-model">
                                @foreach($connection->models as $m)
                                    <option value="{{ $m->model_id }}">{{ $m->model_id }}</option>
                                @endforeach
                            </select>
                            <div class="input-group-append">
                                <button class="btn btn-outline-primary" onclick="runDiagnostic('inference')">
                                    <i class="tio-chat"></i> 3. Test Inference
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div id="diag-result" class="mt-3 p-3 bg-light rounded" style="display:none; font-family: monospace; font-size: 12px; max-height: 200px; overflow-y: auto;">
                    </div>
                </div>
            </div>

            <!-- Models List -->
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0"><i class="tio-layers"></i> Discovered Models</h5>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="runDiagnostic('discovery')">
                        <i class="tio-sync"></i> Sync
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-borderless table-align-middle mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>On</th>
                                    <th>Model ID</th>
                                    <th>Priority</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($connection->models as $m)
                                <tr>
                                    <td>
                                        <div class="custom-control custom-switch">
                                            <input type="checkbox" class="custom-control-input" id="model-toggle-{{$m->id}}" onchange="toggleModel({{$m->id}})" {{$m->is_enabled ? 'checked' : ''}}>
                                            <label class="custom-control-label" for="model-toggle-{{$m->id}}"></label>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="font-weight-bold" style="font-size: 12px; word-break: break-all;">{{$m->model_id}}</div>
                                        @if($m->supports_tool_calling)
                                            <span class="badge badge-soft-success" style="font-size: 10px;">Tools</span>
                                        @endif
                                        @if($m->is_free_tier)
                                            <span class="badge badge-soft-info" style="font-size: 10px;">Free</span>
                                        @endif
                                    </td>
                                    <td>
                                        <input type="number" class="form-control form-control-sm py-0" value="{{$m->priority}}" style="width: 60px;" onchange="updateModelPriority({{$m->id}}, this.value)">
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function toggleModel(modelId) {
    fetch('{{url("admin/whatsapp/ai-providers/models")}}/' + modelId + '/toggle', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': '{{csrf_token()}}',
            'Accept': 'application/json'
        }
    });
}

function updateModelPriority(modelId, priority) {
    fetch('{{url("admin/whatsapp/ai-providers/models")}}/' + modelId + '/update', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': '{{csrf_token()}}',
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        },
        body: JSON.stringify({ priority: priority })
    });
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

function runDiagnostic(type) {
    let url = '{{ url("admin/whatsapp/diagnostics") }}/{{ $connection->id }}/' + type;
    let body = {};
    
    if (type === 'inference') {
        let model = document.getElementById('diag-model');
        if (!model || !model.value) {
            alert('No models available. Please run model discovery first.');
            return;
        }
        body.model_id = model.value;
    }

    const resBox = document.getElementById('diag-result');
    resBox.style.display = 'block';
    resBox.innerHTML = '<span class="spinner-border spinner-border-sm text-primary" role="status"></span> Running ' + type + ' test...';

    fetch(url, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        },
        body: JSON.stringify(body)
    })
    .then(r => r.json())
    .then(data => {
        let color = data.success ? 'green' : 'red';
        resBox.innerHTML = '<div style="color:' + color + '; margin-bottom:10px;"><strong>' + (data.success ? 'PASS' : 'FAIL') + '</strong> - ' + (data.message || '') + '</div>';
        resBox.innerHTML += '<pre style="white-space: pre-wrap; word-wrap: break-word;">' + JSON.stringify(data, null, 2) + '</pre>';
    })
    .catch(e => {
        resBox.innerHTML = '<div style="color:red;"><strong>ERROR</strong></div><pre>' + e.message + '</pre>';
    });
}
</script>
@endsection
