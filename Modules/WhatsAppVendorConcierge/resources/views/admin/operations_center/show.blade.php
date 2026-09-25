@extends('layouts.admin.app')

@section('title', 'Conversation Diagnosis #' . $conversation->id)

@section('content')
<div class="content container-fluid">
    <!-- Breadcrumb & Header -->
    <div class="page-header d-flex flex-wrap justify-content-between align-items-center pb-2 mb-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb fs-12 mb-1">
                    <li class="breadcrumb-item"><a href="{{ route('admin.whatsapp.operations-center.index') }}">Operations Centre</a></li>
                    <li class="breadcrumb-item active">Conversation #{{ $conversation->id }}</li>
                </ol>
            </nav>
            <h1 class="page-header-title d-flex align-items-center gap-2">
                <span>{{ $conversation->contact->name ?? 'Applicant' }}</span>
                <span class="fs-14 font-monospace text-muted">({{ $conversation->contact->phone_number }})</span>
            </h1>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.whatsapp.inbox.show', $conversation->id) }}" class="btn btn-primary btn-sm">
                <i class="tio-chat"></i> Open in Live Inbox
            </a>
            <a href="{{ route('admin.whatsapp.operations-center.index') }}" class="btn btn-outline-secondary btn-sm">
                &larr; Back to Triage List
            </a>
        </div>
    </div>

    <!-- Diagnostic Summary Card -->
    <div class="row g-3 mb-4">
        <div class="col-lg-8">
            <div class="card h-100 border-start border-4 border-{{ $diag['expected_next_responder'] === 'concierge' ? 'danger' : 'info' }}">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0 fs-14"><i class="tio-report"></i> Root Cause Diagnosis</h5>
                    <span class="badge badge-soft-secondary font-monospace">{{ $diag['correlation_id'] }}</span>
                </div>
                <div class="card-body">
                    <div class="alert alert-{{ $diag['is_silenced'] ? 'warning' : 'info' }} mb-3">
                        <strong class="d-block mb-1 fs-14">{{ $diag['human_diagnosis'] }}</strong>
                        <div class="fs-12">
                            Failure Category: <span class="badge badge-secondary font-monospace">{{ $diag['failure_category'] }}</span> |
                            Safety Classification: <span class="badge badge-primary">{{ $diag['safety_classification'] }}</span>
                        </div>
                    </div>

                    <div class="row fs-13 g-2">
                        <div class="col-sm-6">
                            <strong>State:</strong> <span class="font-monospace">{{ $conversation->state }}</span>
                        </div>
                        <div class="col-sm-6">
                            <strong>Current Step:</strong> <span class="font-monospace text-primary">{{ $conversation->current_step ?? 'None' }}</span>
                        </div>
                        <div class="col-sm-6">
                            <strong>Expected Responder:</strong>
                            <span class="badge badge-soft-{{ $diag['expected_next_responder'] === 'concierge' ? 'danger' : 'primary' }}">
                                {{ strtoupper($diag['expected_next_responder']) }}
                            </span>
                        </div>
                        <div class="col-sm-6">
                            <strong>WhatsApp 24h Window:</strong>
                            @if($diag['service_window_open'])
                                <span class="text-success fw-bold"><i class="tio-checkmark"></i> Open (Direct send allowed)</span>
                            @else
                                <span class="text-danger fw-bold"><i class="tio-clear"></i> Expired (Template required)</span>
                            @endif
                        </div>
                        <div class="col-sm-6">
                            <strong>Nudges Sent:</strong> {{ $diag['nudges_sent_count'] }} / 3
                        </div>
                        <div class="col-sm-6">
                            <strong>Nudge Cooldown:</strong>
                            @if($diag['can_nudge'])
                                <span class="text-success">Ready</span>
                            @else
                                <span class="text-warning">Cooldown active ({{ $diag['minutes_since_last_nudge'] }}m ago)</span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Safe Recovery Action Box -->
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header py-2">
                    <h5 class="card-title mb-0 fs-14"><i class="tio-settings"></i> Available Actions</h5>
                </div>
                <div class="card-body d-flex flex-column justify-content-between gap-2">
                    <p class="fs-12 text-muted mb-2">Execute deterministic, verified recovery actions. All actions are logged to the permanent audit trail.</p>

                    <div class="d-grid gap-2">
                        @if($conversation->current_step)
                            <form action="{{ route('admin.whatsapp.operations-center.execute', $conversation->id) }}" method="POST">
                                @csrf
                                <input type="hidden" name="action" value="renudge_current_step">
                                <button type="submit" class="btn btn-outline-primary btn-sm w-100 text-start" onclick="return confirm('Re-send current step prompt for {{ $conversation->current_step }}?')">
                                    <i class="tio-refresh"></i> Re-nudge Step Prompt ({{ $conversation->current_step }})
                                </button>
                            </form>
                        @endif

                        @if($conversation->state === 'human_handoff')
                            <form action="{{ route('admin.whatsapp.operations-center.execute', $conversation->id) }}" method="POST">
                                @csrf
                                <input type="hidden" name="action" value="release_stale_handoff">
                                <button type="submit" class="btn btn-outline-warning btn-sm w-100 text-start" onclick="return confirm('Release from human handoff and resume automation?')">
                                    <i class="tio-play"></i> Release Human Handoff
                                </button>
                            </form>
                        @endif

                        <form action="{{ route('admin.whatsapp.operations-center.execute', $conversation->id) }}" method="POST">
                            @csrf
                            <input type="hidden" name="action" value="reprocess_inbound">
                            <button type="submit" class="btn btn-outline-secondary btn-sm w-100 text-start" onclick="return confirm('Reprocess the last inbound message through the pipeline?')">
                                <i class="tio-redo"></i> Reprocess Last Inbound Message
                            </button>
                        </form>
                    </div>

                    <small class="text-muted fs-11 mt-2">Actions respect WhatsApp opt-in and rate limits.</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Timeline of Recent Messages & Events -->
    <div class="row g-3">
        <!-- Messages -->
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header py-2">
                    <h5 class="card-title mb-0 fs-14"><i class="tio-chat"></i> Recent Message History</h5>
                </div>
                <div class="card-body p-3" style="max-height: 450px; overflow-y: auto;">
                    @forelse($messages as $msg)
                        <div class="d-flex mb-3 {{ $msg->direction === 'outbound' ? 'justify-content-end' : 'justify-content-start' }}">
                            <div class="p-2 rounded {{ $msg->direction === 'outbound' ? 'bg-primary text-white' : 'bg-light text-dark' }}" style="max-width: 80%;">
                                <div class="fs-11 opacity-75 mb-1">
                                    {{ $msg->direction === 'outbound' ? 'Concierge' : 'Applicant' }} &bull; {{ $msg->created_at->format('H:i:s') }}
                                    @if($msg->status)
                                        <span class="badge badge-light text-dark fs-10 ms-1">{{ $msg->status }}</span>
                                    @endif
                                </div>
                                <div class="fs-13">{{ $msg->raw_text ?? '[' . $msg->type . ']' }}</div>
                            </div>
                        </div>
                    @empty
                        <div class="text-center py-4 text-muted">No messages found.</div>
                    @endforelse
                </div>
            </div>
        </div>

        <!-- Audit & Events Trail -->
        <div class="col-lg-5">
            <div class="card mb-3">
                <div class="card-header py-2">
                    <h5 class="card-title mb-0 fs-14"><i class="tio-history"></i> Recovery Audit History</h5>
                </div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush fs-12">
                        @forelse($audits as $audit)
                            <li class="list-group-item">
                                <div class="d-flex justify-content-between">
                                    <strong class="font-monospace text-primary">{{ $audit->action }}</strong>
                                    <span class="badge badge-soft-{{ $audit->status === 'success' ? 'success' : 'danger' }}">{{ $audit->status }}</span>
                                </div>
                                <div class="text-muted fs-11">{{ $audit->created_at->format('Y-m-d H:i') }} &bull; by {{ $audit->initiated_by }}</div>
                                <div class="text-dark mt-1">{{ $audit->reason }}</div>
                            </li>
                        @empty
                            <li class="list-group-item text-muted text-center py-3">No recovery audits for this conversation.</li>
                        @endforelse
                    </ul>
                </div>
            </div>

            <!-- Onboarding Events -->
            <div class="card">
                <div class="card-header py-2">
                    <h5 class="card-title mb-0 fs-14"><i class="tio-time"></i> Step Transition Events</h5>
                </div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush fs-12" style="max-height: 250px; overflow-y: auto;">
                        @forelse($events as $event)
                            <li class="list-group-item py-1">
                                <span class="badge badge-soft-info">{{ $event->event_type }}</span>
                                <span class="font-monospace ms-1">{{ $event->step }}</span>
                                <small class="text-muted d-block fs-10">{{ $event->created_at->diffForHumans() }}</small>
                            </li>
                        @empty
                            <li class="list-group-item text-muted text-center py-2">No event records found.</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
