@extends('layouts.admin.app')

@section('title', translate('AI Providers & Model Routing'))

@section('content')
<div class="content container-fluid">
    <!-- Page Header -->
    <div class="page-header d-flex justify-content-between align-items-center">
        <div>
            <h1 class="page-header-title">
                <span class="page-header-icon"><i class="tio-bot"></i></span>
                <span>{{translate('AI Providers & Multi-Model Routing')}}</span>
            </h1>
            <p class="text-muted mb-0">{{translate('OmniRoute architecture: Connect providers once, discover models automatically, and route with zero downtime.')}}</p>
        </div>
        <div>
            <a href="{{route('admin.whatsapp.ai-providers.create')}}" class="btn btn-primary">
                <i class="tio-add"></i> {{translate('Connect AI Provider')}}
            </a>
        </div>
    </div>

    <!-- Supported Provider Catalogue Cards -->
    <div class="row g-3 mb-4">
        @foreach($definitions as $def)
            @php
                $connectedCount = $connections->where('definition_id', $def->id)->count();
            @endphp
            <div class="col-md-3 col-sm-6">
                <div class="card h-100 shadow-sm border-0">
                    <div class="card-body p-3 d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h5 class="card-title font-weight-bold mb-0">{{$def->name}}</h5>
                                @if($connectedCount > 0)
                                    <span class="badge badge-soft-success">{{$connectedCount}} {{translate('connected')}}</span>
                                @else
                                    <span class="badge badge-soft-secondary">{{translate('available')}}</span>
                                @endif
                            </div>
                            <div class="small text-muted mb-2">
                                @if($def->supports_model_discovery)
                                    <span class="badge badge-soft-info mr-1"><i class="tio-sync"></i> {{translate('Auto-discovery')}}</span>
                                @endif
                                @if($def->supports_tool_calling)
                                    <span class="badge badge-soft-warning"><i class="tio-tools"></i> {{translate('Tools')}}</span>
                                @endif
                            </div>
                        </div>
                        <a href="{{route('admin.whatsapp.ai-providers.create', ['definition_id' => $def->id])}}" class="btn btn-sm btn-outline-primary btn-block mt-2">
                            <i class="tio-add"></i> {{translate('Connect')}}
                        </a>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <!-- Connected Accounts & Discovered Models -->
    <div class="row g-3">
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-header border-0 py-3 d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        {{translate('Connected Provider Accounts')}}
                        <span class="badge badge-soft-primary ml-2">{{$connections->count()}}</span>
                    </h5>
                </div>

                @if($connections->isEmpty())
                    <div class="card-body text-center py-5">
                        <img src="{{asset('/public/assets/admin/svg/illustrations/sorry.svg')}}" alt="empty" style="width: 120px;" class="mb-3">
                        <h5>{{translate('No AI providers connected yet')}}</h5>
                        <p class="text-muted">{{translate('Connect OpenAI, Google Gemini, Groq, OpenRouter, or NVIDIA NIM to power the WhatsApp concierge.')}}</p>
                        <a href="{{route('admin.whatsapp.ai-providers.create')}}" class="btn btn-primary mt-2">
                            <i class="tio-add"></i> {{translate('Connect Your First Provider')}}
                        </a>
                    </div>
                @else
                    <div class="card-body p-0">
                        @foreach($connections as $conn)
                            <div class="border-bottom p-3">
                                <div class="row align-items-center">
                                    <div class="col-md-3">
                                        <div class="d-flex align-items-center">
                                            <span class="avatar avatar-sm avatar-soft-primary mr-3">
                                                <i class="tio-bot font-size-lg"></i>
                                            </span>
                                            <div>
                                                <h5 class="mb-0 font-weight-bold">{{$conn->name}}</h5>
                                                <small class="text-muted">{{$conn->definition->name ?? 'Custom'}}</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div>
                                            <small class="text-muted d-block">{{translate('Status & Health')}}</small>
                                            @if($conn->status === 'healthy')
                                                <span class="badge badge-soft-success"><i class="tio-checkmark-circle"></i> {{translate('Healthy')}}</span>
                                            @elseif($conn->status === 'degraded')
                                                <span class="badge badge-soft-warning"><i class="tio-warning"></i> {{translate('Degraded')}}</span>
                                            @elseif($conn->status === 'cooldown')
                                                <span class="badge badge-soft-danger"><i class="tio-time"></i> {{translate('Cooldown')}}</span>
                                            @elseif($conn->status === 'rate_limited')
                                                <span class="badge badge-soft-danger"><i class="tio-speedometer"></i> {{translate('Rate Limited')}}</span>
                                            @else
                                                <span class="badge badge-soft-secondary">{{$conn->status}}</span>
                                            @endif

                                            @if($conn->daily_budget_usd)
                                                <small class="d-block text-muted mt-1">
                                                    {{translate('Today')}}: ${{number_format($conn->current_day_cost_usd, 4)}} / ${{number_format($conn->daily_budget_usd, 2)}}
                                                </small>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div>
                                            <small class="text-muted d-block">{{translate('Mode & Models')}}</small>
                                            <span class="badge badge-soft-info">{{ucwords(str_replace('_', ' ', $conn->selection_mode))}}</span>
                                            <span class="ml-1 font-weight-bold">{{$conn->models->where('is_enabled', true)->count()}} / {{$conn->models->count()}} {{translate('models')}}</span>
                                        </div>
                                    </div>
                                    <div class="col-md-3 text-right">
                                        <button type="button" class="btn btn-sm btn-outline-info mr-1" onclick="testConnection({{$conn->id}})" id="test-btn-{{$conn->id}}">
                                            <i class="tio-flash"></i> {{translate('Test')}}
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary mr-1" onclick="syncModels({{$conn->id}})" id="sync-btn-{{$conn->id}}">
                                            <i class="tio-sync"></i> {{translate('Sync Models')}}
                                        </button>
                                        <a href="{{route('admin.whatsapp.ai-providers.edit', $conn->id)}}" class="btn btn-sm btn-outline-primary mr-1">
                                            <i class="tio-edit"></i>
                                        </a>
                                        <form action="{{route('admin.whatsapp.ai-providers.destroy', $conn->id)}}" method="POST" class="d-inline" onsubmit="return confirm('{{translate('Delete this connection and all its models?')}}')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="tio-delete"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>

                                <!-- Collapsible / Inline Models List -->
                                <div class="mt-3 bg-light p-3 rounded">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <h6 class="mb-0 font-weight-bold text-dark"><i class="tio-layers"></i> {{translate('Exposed Models')}} ({{$conn->models->count()}})</h6>
                                        <small class="text-muted">{{translate('Toggle models to enable/disable them for WhatsApp routing.')}}</small>
                                    </div>
                                    @if($conn->models->isEmpty())
                                        <div class="text-muted small py-2">{{translate('No models discovered yet. Click "Sync Models" above to fetch compatible models.')}}</div>
                                    @else
                                        <div class="table-responsive">
                                            <table class="table table-sm table-borderless table-align-middle mb-0">
                                                <thead>
                                                    <tr class="text-muted small">
                                                        <th>{{translate('Enabled')}}</th>
                                                        <th>{{translate('Model ID')}}</th>
                                                        <th>{{translate('Capabilities')}}</th>
                                                        <th>{{translate('Tier')}}</th>
                                                        <th>{{translate('Priority')}}</th>
                                                        <th>{{translate('Context')}}</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach($conn->models as $m)
                                                        <tr>
                                                            <td style="width: 60px;">
                                                                <input type="checkbox" onchange="toggleModel({{$m->id}})" {{$m->is_enabled ? 'checked' : ''}}>
                                                            </td>
                                                            <td>
                                                                <span class="font-weight-bold">{{$m->name}}</span>
                                                                <code class="d-block small text-muted">{{$m->model_id}}</code>
                                                            </td>
                                                            <td>
                                                                @if($m->supports_tool_calling)
                                                                    <span class="badge badge-soft-success mr-1" title="Vendor management tools"><i class="tio-tools"></i> {{translate('Tools')}}</span>
                                                                @else
                                                                    <span class="badge badge-soft-secondary mr-1">{{translate('Chat only')}}</span>
                                                                @endif
                                                                @if($m->supports_vision)
                                                                    <span class="badge badge-soft-info mr-1" title="Image analysis"><i class="tio-visible"></i> {{translate('Vision')}}</span>
                                                                @endif
                                                            </td>
                                                            <td>
                                                                @if($m->is_free_tier)
                                                                    <span class="badge badge-success font-weight-bold">🟢 {{translate('FREE')}}</span>
                                                                @else
                                                                    <span class="badge badge-soft-dark">💳 {{translate('Paid')}}</span>
                                                                @endif
                                                            </td>
                                                            <td style="width: 120px;">
                                                                <input type="number" class="form-control form-control-sm py-0" value="{{$m->priority}}" style="width: 75px;" onchange="updateModelPriority({{$m->id}}, this.value)">
                                                            </td>
                                                            <td>
                                                                <small class="text-muted">{{number_format($m->context_window ?? 0)}} tokens</small>
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

<script>
function testConnection(id) {
    const btn = document.getElementById('test-btn-' + id);
    const originalText = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Testing...';
    btn.disabled = true;

    fetch('{{url("admin/whatsapp/ai-providers/test")}}/' + id, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': '{{csrf_token()}}',
            'Accept': 'application/json',
            'Content-Type': 'application/json'
        }
    })
    .then(r => r.json())
    .then(data => {
        btn.innerHTML = originalText;
        btn.disabled = false;
        alert(data.message + (data.latency_ms ? ' (' + data.latency_ms + 'ms)' : ''));
        if (data.success) location.reload();
    })
    .catch(e => {
        btn.innerHTML = originalText;
        btn.disabled = false;
        alert('Test failed: ' + e.message);
    });
}

function syncModels(id) {
    const btn = document.getElementById('sync-btn-' + id);
    const originalText = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Syncing...';
    btn.disabled = true;

    fetch('{{url("admin/whatsapp/ai-providers/sync-models")}}/' + id, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': '{{csrf_token()}}',
            'Accept': 'application/json',
            'Content-Type': 'application/json'
        }
    })
    .then(r => r.json())
    .then(data => {
        btn.innerHTML = originalText;
        btn.disabled = false;
        alert(data.message);
        location.reload();
    })
    .catch(e => {
        btn.innerHTML = originalText;
        btn.disabled = false;
        alert('Sync failed: ' + e.message);
    });
}

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
</script>
@endsection
