@extends('layouts.admin.app')

@section('title', 'Concierge Recovery Audit Trail')

@section('content')
<div class="content container-fluid">
    <div class="page-header d-flex flex-wrap justify-content-between align-items-center pb-2 mb-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb fs-12 mb-1">
                    <li class="breadcrumb-item"><a href="{{ route('admin.whatsapp.operations-center.index') }}">Operations Centre</a></li>
                    <li class="breadcrumb-item active">Audit Trail</li>
                </ol>
            </nav>
            <h1 class="page-header-title">Recovery Operations Audit Trail</h1>
            <p class="text-muted fs-13 mb-0">Chronological record of all manual and automated recovery operations.</p>
        </div>
        <div>
            <a href="{{ route('admin.whatsapp.operations-center.index') }}" class="btn btn-outline-secondary btn-sm">
                &larr; Back to Operations Centre
            </a>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <form action="{{ route('admin.whatsapp.operations-center.audits') }}" method="GET" class="row align-items-center">
                <div class="col-md-3">
                    <select name="action" class="form-control form-control-sm" onchange="this.form.submit()">
                        <option value="">All Actions</option>
                        <option value="renudge_current_step" {{ request('action') === 'renudge_current_step' ? 'selected' : '' }}>Re-nudge Current Step</option>
                        <option value="release_stale_handoff" {{ request('action') === 'release_stale_handoff' ? 'selected' : '' }}>Release Stale Handoff</option>
                        <option value="reprocess_inbound" {{ request('action') === 'reprocess_inbound' ? 'selected' : '' }}>Reprocess Inbound Message</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="status" class="form-control form-control-sm" onchange="this.form.submit()">
                        <option value="">All Statuses</option>
                        <option value="success" {{ request('status') === 'success' ? 'selected' : '' }}>Success</option>
                        <option value="failed" {{ request('status') === 'failed' ? 'selected' : '' }}>Failed</option>
                        <option value="skipped" {{ request('status') === 'skipped' ? 'selected' : '' }}>Skipped</option>
                        <option value="excluded" {{ request('status') === 'excluded' ? 'selected' : '' }}>Excluded</option><option value="dry_run_passed" {{ request('status') === 'dry_run_passed' ? 'selected' : '' }}>Preview passed</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <a href="{{ route('admin.whatsapp.operations-center.audits') }}" class="btn btn-outline-secondary btn-sm">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Table -->
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover table-thead-bordered table-nowrap table-align-middle card-table mb-0 fs-13">
                <thead class="thead-light">
                    <tr>
                        <th>Time</th>
                        <th>Conversation</th>
                        <th>Action</th>
                        <th>Initiator</th>
                        <th>State Transition</th>
                        <th>Status</th>
                        <th>Reason / Notes</th>
                        <th>Correlation Trace</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($audits as $audit)
                        <tr>
                            <td>{{ $audit->created_at->format('Y-m-d H:i:s') }}</td>
                            <td>
                                @if($audit->conversation_id)
                                    <a href="{{ route('admin.whatsapp.operations-center.show', $audit->conversation_id) }}" class="font-weight-bold">
                                        #{{ $audit->conversation_id }}
                                    </a>
                                    <small class="text-muted d-block">{{ $audit->conversation?->contact?->phone_number ?? '-' }}</small>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                            <td><span class="badge badge-soft-primary font-monospace">{{ $audit->action }}</span></td>
                            <td><span class="badge badge-soft-secondary">{{ $audit->initiated_by }}</span></td>
                            <td>
                                @if($audit->previous_state || $audit->proposed_state)
                                    {{ $audit->previous_state ?? '-' }} &rarr; <strong>{{ $audit->proposed_state ?? '-' }}</strong>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge badge-soft-{{ $audit->status === 'success' ? 'success' : ($audit->status === 'failed' ? 'danger' : 'warning') }}">
                                    {{ strtoupper($audit->status) }}
                                </span>
                            </td>
                            <td>
                                <div class="text-truncate" style="max-width: 250px;" title="{{ $audit->reason }}">
                                    {{ $audit->reason ?? '-' }}
                                </div>
                            </td>
                            <td><span class="font-monospace text-muted fs-11">{{ $audit->correlation_id }}</span></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">No audit logs match your search.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($audits->hasPages())
            <div class="card-footer py-2">
                {{ $audits->appends(request()->query())->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
