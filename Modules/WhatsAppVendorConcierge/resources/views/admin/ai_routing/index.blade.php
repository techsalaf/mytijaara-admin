@extends('layouts.admin.app')

@section('title', translate('AI Routing Engine & Simulation'))

@section('content')
<div class="content container-fluid">
    <!-- Header -->
    <div class="page-header d-flex justify-content-between align-items-center">
        <div>
            <h1 class="page-header-title">
                <span class="page-header-icon"><i class="tio-filter-list"></i></span>
                <span>{{translate('AI Routing Engine & Simulator')}}</span>
            </h1>
            <p class="text-muted mb-0">{{translate('Configure intelligent model fallback policies and simulate routing live.')}}</p>
        </div>
        <div>
            <a href="{{route('admin.whatsapp.ai-providers.index')}}" class="btn btn-outline-primary">
                <i class="tio-bot"></i> {{translate('Manage Providers')}}
            </a>
        </div>
    </div>

    <!-- Quick Metrics (Today) -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-lg-3">
            <div class="card card-body h-100 shadow-sm border-0">
                <h6 class="card-subtitle text-muted mb-1">{{translate('Today Total Requests')}}</h6>
                <div class="d-flex align-items-baseline">
                    <span class="h2 mb-0 mr-2">{{number_format($stats['total_requests'])}}</span>
                    <span class="badge badge-soft-success">{{$stats['successful_requests']}} {{translate('ok')}}</span>
                    @if($stats['failed_requests'] > 0)
                        <span class="badge badge-soft-danger ml-1">{{$stats['failed_requests']}} {{translate('failed')}}</span>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="card card-body h-100 shadow-sm border-0">
                <h6 class="card-subtitle text-muted mb-1">{{translate('Tokens Consumed (Today)')}}</h6>
                <div class="d-flex align-items-baseline">
                    <span class="h2 mb-0">{{number_format($stats['total_tokens'])}}</span>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="card card-body h-100 shadow-sm border-0">
                <h6 class="card-subtitle text-muted mb-1">{{translate('Estimated Cost (Today)')}}</h6>
                <div class="d-flex align-items-baseline">
                    <span class="h2 mb-0 text-success">${{number_format($stats['total_cost_usd'], 4)}}</span>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="card card-body h-100 shadow-sm border-0">
                <h6 class="card-subtitle text-muted mb-1">{{translate('Available Models')}}</h6>
                <div class="d-flex align-items-baseline">
                    <span class="h2 mb-0 mr-2">{{$availableModels->count()}}</span>
                    <span class="badge badge-success">{{$availableModels->where('is_free_tier', true)->count()}} {{translate('Free')}}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <!-- Routing Policy Configuration -->
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header border-0 py-3">
                    <h5 class="card-title mb-0"><i class="tio-settings"></i> {{translate('Active Routing Policy')}}</h5>
                </div>
                <div class="card-body">
                    @foreach($policies as $policy)
                        <form action="{{route('admin.whatsapp.ai-routing.policy.update', $policy->id)}}" method="POST">
                            @csrf
                            @method('PUT')

                            <div class="form-group mb-3">
                                <label class="input-label font-weight-bold" for="policy_strategy">{{translate('Routing Strategy')}}</label>
                                <select name="strategy" id="policy_strategy" class="form-control" required>
                                    <option value="free_first" {{$policy->strategy === 'free_first' ? 'selected' : ''}}>
                                        🟢 {{translate('Free First (Prioritise all free models, paid as emergency fallback)')}}
                                    </option>
                                    <option value="strict_fallback" {{$policy->strategy === 'strict_fallback' ? 'selected' : ''}}>
                                        🎯 {{translate('Strict Fallback (Ordered strictly by model priority)')}}
                                    </option>
                                    <option value="round_robin" {{$policy->strategy === 'round_robin' ? 'selected' : ''}}>
                                        🔄 {{translate('Round Robin (Cycle across top eligible models)')}}
                                    </option>
                                    <option value="lowest_cost" {{$policy->strategy === 'lowest_cost' ? 'selected' : ''}}>
                                        💰 {{translate('Lowest Cost (Order by token price per million)')}}
                                    </option>
                                    <option value="quality_first" {{$policy->strategy === 'quality_first' ? 'selected' : ''}}>
                                        ⭐ {{translate('Quality First (Prioritise paid flagship models, free as fallback)')}}
                                    </option>
                                </select>
                            </div>

                            <div class="form-group mb-3">
                                <div class="custom-control custom-checkbox mb-2">
                                    <input type="checkbox" class="custom-control-input" id="requires_tool_calling" name="requires_tool_calling" value="1" {{$policy->requires_tool_calling ? 'checked' : ''}}>
                                    <label class="custom-control-label font-weight-bold" for="requires_tool_calling">
                                        {{translate('Enforce Tool Calling Verification')}}
                                    </label>
                                    <small class="d-block text-muted">{{translate('Route vendor assistant requests exclusively to models with confirmed function/tool calling support.')}}</small>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end mt-4">
                                <button type="submit" class="btn btn-primary">
                                    <i class="tio-save"></i> {{translate('Save Policy Rules')}}
                                </button>
                            </div>
                        </form>
                    @endforeach
                </div>
            </div>
        </div>

        <!-- Live Simulation Console -->
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header border-0 py-3">
                    <h5 class="card-title mb-0"><i class="tio-play"></i> {{translate('Live Routing Simulation Console')}}</h5>
                </div>
                <div class="card-body">
                    <p class="small text-muted mb-3">{{translate('Test how your configured routing policy behaves for incoming vendor prompts without messaging WhatsApp.')}}</p>

                    <div class="form-group mb-3">
                        <label class="input-label font-weight-bold" for="sim_prompt">{{translate('Test Prompt')}}</label>
                        <textarea id="sim_prompt" class="form-control" rows="3" placeholder="e.g. Hello, what orders did I receive today?">Hello, how are you?</textarea>
                    </div>

                    <button type="button" class="btn btn-success btn-block" id="sim-btn" onclick="runSimulation()">
                        <i class="tio-flash"></i> {{translate('Simulate Prompt Routing')}}
                    </button>

                    <!-- Simulation Result Panel -->
                    <div id="sim-result" class="mt-4 p-3 bg-light rounded" style="display: none;">
                        <h6 class="font-weight-bold mb-2 text-dark"><i class="tio-checkmark-circle text-success"></i> {{translate('Simulation Result')}}</h6>
                        <div class="row small mb-2">
                            <div class="col-6"><strong>{{translate('Chosen Model')}}:</strong> <span id="res-model"></span> <span id="res-tier" class="badge"></span></div>
                            <div class="col-6"><strong>{{translate('Connection')}}:</strong> <span id="res-conn"></span></div>
                            <div class="col-6 mt-1"><strong>{{translate('Latency')}}:</strong> <span id="res-latency"></span>ms</div>
                            <div class="col-6 mt-1"><strong>{{translate('Est. Cost')}}:</strong> $<span id="res-cost"></span></div>
                        </div>
                        <div class="small">
                            <strong>{{translate('Generated Reply')}}:</strong>
                            <div id="res-text" class="p-2 bg-white rounded border mt-1 font-italic"></div>
                        </div>
                    </div>

                    <div id="sim-error" class="mt-4 p-3 alert alert-danger" style="display: none;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Routing Attempts Table -->
    <div class="row g-3 mt-3">
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-header border-0 py-3">
                    <h5 class="card-title mb-0"><i class="tio-history"></i> {{translate('Recent Routing Attempts')}}</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-borderless table-thead-bordered table-nowrap table-align-middle card-table">
                        <thead class="thead-light">
                            <tr>
                                <th>{{translate('Time')}}</th>
                                <th>{{translate('Provider Account')}}</th>
                                <th>{{translate('Model')}}</th>
                                <th>{{translate('Status')}}</th>
                                <th>{{translate('Tokens (In/Out)')}}</th>
                                <th>{{translate('Cost')}}</th>
                                <th>{{translate('Latency')}}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($recentAttempts as $attempt)
                                <tr>
                                    <td>{{$attempt->created_at->format('M d, H:i:s')}}</td>
                                    <td>{{$attempt->connection->name ?? 'N/A'}}</td>
                                    <td><code>{{$attempt->model->model_id ?? 'N/A'}}</code></td>
                                    <td>
                                        @if($attempt->status === 'success')
                                            <span class="badge badge-soft-success">{{translate('Success')}}</span>
                                        @else
                                            <span class="badge badge-soft-danger" title="{{$attempt->error_message}}">{{$attempt->status}}</span>
                                        @endif
                                    </td>
                                    <td>{{$attempt->prompt_tokens}} / {{$attempt->completion_tokens}}</td>
                                    <td>${{number_format($attempt->cost_usd, 6)}}</td>
                                    <td>{{$attempt->latency_ms}}ms</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">{{translate('No routing attempts recorded yet.')}}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function runSimulation() {
    const btn = document.getElementById('sim-btn');
    const resultBox = document.getElementById('sim-result');
    const errorBox = document.getElementById('sim-error');
    const prompt = document.getElementById('sim_prompt').value;

    resultBox.style.display = 'none';
    errorBox.style.display = 'none';

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Simulating...';

    fetch('{{route("admin.whatsapp.ai-routing.simulate")}}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': '{{csrf_token()}}',
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        },
        body: JSON.stringify({ prompt: prompt })
    })
    .then(r => r.json().then(data => ({ status: r.status, body: data })))
    .then(res => {
        btn.disabled = false;
        btn.innerHTML = '<i class="tio-flash"></i> {{translate("Simulate Prompt Routing")}}';

        if (res.body.success) {
            document.getElementById('res-model').innerText = res.body.model;
            document.getElementById('res-conn').innerText = res.body.connection;
            document.getElementById('res-latency').innerText = res.body.latency_ms;
            document.getElementById('res-cost').innerText = res.body.cost_usd;
            document.getElementById('res-text').innerText = res.body.response_text;

            const tierBadge = document.getElementById('res-tier');
            if (res.body.is_free) {
                tierBadge.className = 'badge badge-success';
                tierBadge.innerText = 'FREE';
            } else {
                tierBadge.className = 'badge badge-soft-dark';
                tierBadge.innerText = 'PAID';
            }

            resultBox.style.display = 'block';
        } else {
            errorBox.innerText = res.body.message || 'Simulation error';
            errorBox.style.display = 'block';
        }
    })
    .catch(e => {
        btn.disabled = false;
        btn.innerHTML = '<i class="tio-flash"></i> {{translate("Simulate Prompt Routing")}}';
        errorBox.innerText = 'Simulation failed: ' + e.message;
        errorBox.style.display = 'block';
    });
}
</script>
@endsection
