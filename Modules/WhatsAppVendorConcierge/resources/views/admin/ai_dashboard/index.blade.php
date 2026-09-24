@extends('layouts.admin.app')

@section('title', 'AI WhatsApp Dashboard')

@section('content')
<div class="content container-fluid">
    <div class="page-header">
        <h1 class="page-header-title">
            <span class="page-header-icon">
                <i class="tio-bot"></i>
            </span>
            <span>AI Operations Dashboard</span>
        </h1>
    </div>

    <!-- Overview Section -->
    <div class="row gx-2 gx-lg-3 mb-3 mb-lg-5">
        <div class="col-sm-6 col-lg-3 mb-3 mb-lg-0">
            <div class="card h-100">
                <div class="card-body">
                    <h6 class="card-subtitle mb-2">Modern AI Routes</h6>
                    <div class="row align-items-center gx-2">
                        <div class="col">
                            <span class="js-counter display-4 text-dark">{{ $modernConnections->count() }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3 mb-3 mb-lg-0">
            <div class="card h-100">
                <div class="card-body">
                    <h6 class="card-subtitle mb-2">Active Working Routes</h6>
                    <div class="row align-items-center gx-2">
                        <div class="col">
                            <span class="js-counter display-4 text-success">{{ $activeRoutesCount }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3 mb-3 mb-lg-0">
            <div class="card h-100">
                <div class="card-body">
                    <h6 class="card-subtitle mb-2">Legacy Fallbacks Active</h6>
                    <div class="row align-items-center gx-2">
                        <div class="col">
                            <span class="js-counter display-4 text-warning">{{ $legacyActive }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3 mb-3 mb-lg-0">
            <div class="card h-100">
                <div class="card-body">
                    <h6 class="card-subtitle mb-2">Today's Cost</h6>
                    <div class="row align-items-center gx-2">
                        <div class="col">
                            <span class="display-4 text-dark">${{ number_format($totalDailyCost, 4) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <ul class="nav nav-tabs page-header-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link active" href="#">Overview</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="{{ route('admin.whatsapp.ai-providers.index') }}">Providers & Models</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="{{ route('admin.whatsapp.ai-routing.index') }}">Routing & Fallbacks</a>
        </li>
        </ul>

    <!-- Quick Actions -->
    <div class="card">
        <div class="card-header">
            <h5 class="card-header-title">System Status</h5>
        </div>
        <div class="card-body">
            @if($activeRoutesCount > 0)
                <div class="alert alert-soft-success">
                    <i class="tio-checkmark-circle"></i> The modern OmniRoute AI system is operational with {{ $activeRoutesCount }} working routes.
                </div>
            @elseif($legacyActive > 0)
                <div class="alert alert-soft-warning">
                    <i class="tio-warning"></i> No modern connections are verified. The system will fall back to legacy providers.
                </div>
            @else
                <div class="alert alert-soft-danger">
                    <i class="tio-error"></i> CRITICAL: No AI providers are configured or verified. WhatsApp Concierge cannot reply.
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
