@extends('layouts.admin.app')

@section('title', translate('AI Providers'))

@section('content')
<div class="content container-fluid">
    <div class="page-header">
        <div class="row align-items-center">
            <div class="col-sm mb-2 mb-sm-0">
                <h1 class="page-header-title"><i class="tio-settings"></i> {{translate('AI Providers & Models')}}</h1>
                <p class="text-muted">{{translate('Configure AI platform credentials and discover models.')}}</p>
            </div>
            <div class="col-sm-auto">
                <a class="btn btn-primary" href="{{route('admin.whatsapp.ai-providers.create')}}">
                    <i class="tio-add"></i> {{translate('Add Provider Connection')}}
                </a>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <ul class="nav nav-tabs page-header-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link" href="{{ route('admin.whatsapp.ai-dashboard.index') }}">Overview</a>
        </li>
        <li class="nav-item">
            <a class="nav-link active" href="#">Providers & Models</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="{{ route('admin.whatsapp.ai-routing.index') }}">Routing & Fallbacks</a>
        </li>
        </ul>

    <div class="card shadow-sm">
        <div class="card-header border-bottom">
            <h5 class="card-header-title">Active Provider Connections</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-borderless table-thead-bordered table-nowrap table-align-middle card-table">
                <thead class="thead-light">
                    <tr>
                        <th>{{translate('Platform')}}</th>
                        <th>{{translate('Account Label')}}</th>
                        <th>{{translate('Health Status')}}</th>
                        <th>{{translate('Models')}}</th>
                        <th>{{translate('Selection')}}</th>
                        <th class="text-right">{{translate('Actions')}}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($connections as $conn)
                    <tr>
                        <td>
                            <div class="d-flex align-items-center">
                                <div class="avatar avatar-sm avatar-circle mr-3">
                                    <img class="avatar-img" src="{{ asset('public/assets/admin/img/160x160/img1.jpg') }}" alt="Provider">
                                </div>
                                <div>
                                    <h5 class="mb-0">{{ $conn->definition->name ?? 'Custom' }}</h5>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="d-block font-weight-bold">{{ $conn->name }}</span>
                        </td>
                        <td>
                            @if(in_array($conn->status, ['inference_verified', 'tool_calling_verified']))
                                <span class="badge badge-soft-success"><i class="tio-checkmark-circle"></i> VERIFIED</span>
                            @elseif(in_array($conn->status, ['unverified', 'unconfigured', 'models_discovered']))
                                <span class="badge badge-soft-warning"><i class="tio-help-outlined"></i> UNVERIFIED</span>
                            @elseif(in_array($conn->status, ['degraded', 'rate_limited', 'quota_exhausted']))
                                <span class="badge badge-soft-warning"><i class="tio-warning"></i> DEGRADED</span>
                            @else
                                <span class="badge badge-soft-danger"><i class="tio-error"></i> FAILED</span>
                            @endif
                            <div class="small text-muted mt-1">{{ strtoupper($conn->status) }}</div>
                        </td>
                        <td>
                            <span class="badge badge-soft-info">{{ $conn->models->count() }} Discovered</span>
                            <div class="small text-muted mt-1">{{ $conn->models->where('is_enabled', true)->count() }} Enabled</div>
                        </td>
                        <td>
                            <span class="d-block">{{ str_replace('_', ' ', Str::title($conn->selection_mode)) }}</span>
                        </td>
                        <td class="text-right">
                            <a href="{{route('admin.whatsapp.ai-providers.edit', $conn->id)}}" class="btn btn-sm btn-outline-primary mr-1">
                                <i class="tio-settings"></i> {{translate('Configure')}}
                            </a>
                            <form action="{{route('admin.whatsapp.ai-providers.destroy', $conn->id)}}" method="POST" class="d-inline" onsubmit="return confirm('{{translate('Delete this connection and all its models?')}}')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="tio-delete"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="text-center py-5">
                            <div class="text-muted"><i class="tio-help-outlined" style="font-size: 2rem;"></i></div>
                            <h5 class="mt-2">No AI Providers Configured</h5>
                            <p class="text-muted">Click the button above to add your first AI provider connection.</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
