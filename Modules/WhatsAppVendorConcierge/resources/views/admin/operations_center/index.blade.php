@extends('layouts.admin.app')

@section('title', 'Concierge Operations Centre')

@push('css_or_js')
<style>
    .op-kpi-card {
        border-radius: 10px;
        transition: transform 0.15s ease, box-shadow 0.15s ease;
        text-decoration: none !important;
        color: inherit !important;
        display: block;
    }
    .op-kpi-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    }
    .badge-safe-auto { background-color: #d1e7dd; color: #0f5132; }
    .badge-safe-manual { background-color: #cff4fc; color: #055160; }
    .badge-human-required { background-color: #fff3cd; color: #664d03; }
    .badge-code-defect { background-color: #f8d7da; color: #842029; }
    .badge-healthy { background-color: #e2e3e5; color: #41464b; }
    .progress-bar-thin { height: 5px; border-radius: 3px; }
</style>
@endpush

@section('content')
<div class="content container-fluid">
    <!-- Page Header -->
    <div class="page-header d-flex flex-wrap justify-content-between align-items-center pb-2 mb-3">
        <div>
            <h1 class="page-header-title d-flex align-items-center gap-2">
                <span>Concierge Operations Centre</span>
                @if($overview['system_health'] === 'healthy')
                    <span class="badge badge-success fs-12 px-2 py-1"><i class="tio-checkmark-circle"></i> System Healthy</span>
                @elseif($overview['system_health'] === 'critical')
                    <span class="badge badge-danger fs-12 px-2 py-1"><i class="tio-error"></i> Critical Attention Needed</span>
                @else
                    <span class="badge badge-warning fs-12 px-2 py-1"><i class="tio-warning"></i> Issues Detected</span>
                @endif
            </h1>
            <p class="text-muted fs-13 mb-0">Unified operations, root-cause diagnosis, dry-run previews, and safe automated recovery for WhatsApp Vendor Concierge.</p>
        </div>
        <div class="d-flex gap-2">
            <form action="{{ route('admin.whatsapp.operations-center.health-check') }}" method="POST" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-outline-primary btn-sm">
                    <i class="tio-refresh"></i> Run Health Scan
                </button>
            </form>
            <a href="{{ route('admin.whatsapp.operations-center.audits') }}" class="btn btn-outline-secondary btn-sm">
                <i class="tio-history"></i> Audit Trail
            </a>
            <a href="{{ route('admin.whatsapp.inbox.index') }}" class="btn btn-primary btn-sm">
                <i class="tio-chat"></i> Live Inbox
            </a>
        </div>
    </div>

    <!-- Operations Overview Metrics -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-lg-3">
            <a href="{{ route('admin.whatsapp.operations-center.index') }}" class="card op-kpi-card h-100 border-start border-4 border-primary">
                <div class="card-body">
                    <div class="text-muted text-uppercase fs-12 fw-bold mb-1">Active Onboarding</div>
                    <div class="d-flex align-items-center justify-content-between">
                        <span class="h2 mb-0 text-primary">{{ $overview['total_active_onboarding'] }}</span>
                        <span class="tio-user-big fs-28 text-muted"></span>
                    </div>
                    <small class="text-muted">In-flight registrations</small>
                </div>
            </a>
        </div>
        <div class="col-sm-6 col-lg-3">
            <a href="{{ route('admin.whatsapp.operations-center.index', ['filter' => 'waiting_concierge']) }}" class="card op-kpi-card h-100 border-start border-4 border-danger">
                <div class="card-body">
                    <div class="text-muted text-uppercase fs-12 fw-bold mb-1">Waiting For Concierge</div>
                    <div class="d-flex align-items-center justify-content-between">
                        <span class="h2 mb-0 text-danger">{{ $overview['waiting_for_concierge'] }}</span>
                        <span class="tio-clock fs-28 text-danger"></span>
                    </div>
                    <small class="text-danger fw-semibold">{{ $overview['silenced_conversations'] }} silenced (needs reply)</small>
                </div>
            </a>
        </div>
        <div class="col-sm-6 col-lg-3">
            <a href="{{ route('admin.whatsapp.operations-center.index', ['filter' => 'stale_handoff']) }}" class="card op-kpi-card h-100 border-start border-4 border-warning">
                <div class="card-body">
                    <div class="text-muted text-uppercase fs-12 fw-bold mb-1">Stale Handoffs (>2h)</div>
                    <div class="d-flex align-items-center justify-content-between">
                        <span class="h2 mb-0 text-warning">{{ $overview['stale_human_handoff'] }}</span>
                        <span class="tio-pause-circle fs-28 text-warning"></span>
                    </div>
                    <small class="text-muted">Total in handoff: {{ $overview['in_human_handoff'] }}</small>
                </div>
            </a>
        </div>
        <div class="col-sm-6 col-lg-3">
            <a href="{{ route('admin.whatsapp.operations-center.index', ['filter' => 'waiting_user']) }}" class="card op-kpi-card h-100 border-start border-4 border-info">
                <div class="card-body">
                    <div class="text-muted text-uppercase fs-12 fw-bold mb-1">Waiting For User</div>
                    <div class="d-flex align-items-center justify-content-between">
                        <span class="h2 mb-0 text-info">{{ $overview['waiting_for_user'] }}</span>
                        <span class="tio-send fs-28 text-info"></span>
                    </div>
                    <small class="text-muted">Prompt sent, awaiting reply</small>
                </div>
            </a>
        </div>
    </div>

    <!-- Secondary Stats Bar -->
    <div class="card mb-4 bg-light border-0">
        <div class="card-body py-2">
            <div class="row text-center text-md-start">
                <div class="col-md-3 py-1">
                    <small class="text-muted">Vendors Awaiting Approval:</small>
                    <span class="fw-bold ms-1 text-dark">{{ $overview['vendors_awaiting_approval'] }}</span>
                </div>
                <div class="col-md-3 py-1">
                    <small class="text-muted">Failed Outbounds (48h):</small>
                    <span class="fw-bold ms-1 text-danger">{{ $overview['failed_outbounds'] }}</span>
                </div>
                <div class="col-md-3 py-1">
                    <small class="text-muted">Recovered (24h):</small>
                    <span class="fw-bold ms-1 text-success">{{ $overview['recently_recovered'] }} users</span>
                </div>
                <div class="col-md-3 py-1 text-md-end">
                    <small class="text-muted">Latest Health Scan:</small>
                    <span class="fw-semibold ms-1">{{ $latestHealthCheck ? $latestHealthCheck->created_at->diffForHumans() : 'Never' }}</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Triage & Actions Table -->
    <div class="card">
        <!-- Card Header / Filters -->
        <div class="card-header border-bottom py-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 w-100">
                <!-- Filter Pills -->
                <div class="d-flex flex-wrap gap-2">
                    <a href="{{ route('admin.whatsapp.operations-center.index') }}" 
                       class="btn btn-sm {{ $filter === 'all' ? 'btn-primary' : 'btn-outline-secondary' }}">
                       All Active
                    </a>
                    <a href="{{ route('admin.whatsapp.operations-center.index', ['filter' => 'waiting_concierge']) }}" 
                       class="btn btn-sm {{ $filter === 'waiting_concierge' ? 'btn-danger' : 'btn-outline-danger' }}">
                       Waiting For Concierge ({{ $overview['waiting_for_concierge'] }})
                    </a>
                    <a href="{{ route('admin.whatsapp.operations-center.index', ['filter' => 'stale_handoff']) }}" 
                       class="btn btn-sm {{ $filter === 'stale_handoff' ? 'btn-warning' : 'btn-outline-warning' }}">
                       Stale Handoffs ({{ $overview['stale_human_handoff'] }})
                    </a>
                    <a href="{{ route('admin.whatsapp.operations-center.index', ['filter' => 'waiting_user']) }}" 
                       class="btn btn-sm {{ $filter === 'waiting_user' ? 'btn-info' : 'btn-outline-info' }}">
                       Waiting For User ({{ $overview['waiting_for_user'] }})
                    </a>
                </div>

                <!-- Search -->
                <form action="{{ route('admin.whatsapp.operations-center.index') }}" method="GET" class="d-flex gap-2">
                    <input type="hidden" name="filter" value="{{ $filter }}">
                    <div class="input-group input-group-sm" style="width: 250px;">
                        <input type="text" name="search" class="form-control" placeholder="Search phone or name..." value="{{ $search }}">
                        <button class="btn btn-secondary" type="submit"><i class="tio-search"></i></button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Bulk Action Controls -->
        <div class="card-body bg-light py-2 border-bottom">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div class="d-flex align-items-center gap-2">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="selectAll">
                        <label class="form-check-label fs-13 text-muted" for="selectAll">Select All</label>
                    </div>
                    <span class="fs-13 text-muted ms-2" id="selectedCount">0 selected</span>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <select class="form-select form-select-sm" id="bulkActionSelect" style="width: 230px;">
                        <option value="">-- Choose Bulk Action --</option>
                        <option value="renudge_current_step">Re-nudge Current Step Prompt</option>
                        <option value="release_stale_handoff">Release Stale Human Handoff</option>
                        <option value="reprocess_inbound">Reprocess Last Inbound Message</option>
                    </select>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnPreviewBulk">
                        <i class="tio-preview"></i> Preview Dry Run
                    </button>
                </div>
            </div>
        </div>

        <!-- Table -->
        <div class="table-responsive">
            <table class="table table-hover table-thead-bordered table-nowrap table-align-middle card-table mb-0">
                <thead class="thead-light">
                    <tr>
                        <th width="40"></th>
                        <th>User Identity</th>
                        <th>State & Step</th>
                        <th>Last Inbound</th>
                        <th>Last Outbound</th>
                        <th>Next Responder</th>
                        <th>Diagnosis & Root Cause</th>
                        <th>Safety</th>
                        <th class="text-end">Recovery Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($diagnosedItems as $item)
                        @php
                            $c = $item['conversation'];
                            $d = $item['diag'];
                        @endphp
                        <tr>
                            <td>
                                <input type="checkbox" class="form-check-input row-checkbox" value="{{ $c->id }}">
                            </td>
                            <td>
                                <div class="fw-bold text-dark">{{ $c->contact->name ?? 'Applicant' }}</div>
                                <div class="text-muted fs-12">{{ $c->contact->phone_number }}</div>
                                <div class="progress progress-bar-thin mt-1" style="width: 80px;" title="{{ $d['progress_percentage'] }}% completed">
                                    <div class="progress-bar bg-success" role="progressbar" style="width: {{ $d['progress_percentage'] }}%"></div>
                                </div>
                            </td>
                            <td>
                                <span class="badge badge-soft-{{ $c->state === 'onboarding_active' ? 'primary' : ($c->state === 'human_handoff' ? 'warning' : 'secondary') }}">
                                    {{ str_replace('_', ' ', $c->state) }}
                                </span>
                                @if($c->current_step)
                                    <div class="text-muted fs-11 mt-1 font-monospace">{{ $c->current_step }}</div>
                                @endif
                            </td>
                            <td>
                                @if($d['last_inbound'])
                                    <div class="text-dark fs-12 text-truncate" style="max-width: 140px;" title="{{ $d['last_inbound']['text'] }}">
                                        {{ $d['last_inbound']['text'] ?? '[' . $d['last_inbound']['type'] . ']' }}
                                    </div>
                                    <small class="text-muted fs-11">{{ $d['last_inbound']['readable_time'] }}</small>
                                @else
                                    <span class="text-muted fs-12">None</span>
                                @endif
                            </td>
                            <td>
                                @if($d['last_outbound'])
                                    <div class="text-dark fs-12 text-truncate" style="max-width: 140px;" title="{{ $d['last_outbound']['text'] }}">
                                        {{ $d['last_outbound']['text'] ?? '[' . $d['last_outbound']['type'] . ']' }}
                                    </div>
                                    <small class="text-muted fs-11">
                                        {{ $d['last_outbound']['readable_time'] }}
                                        @if(($d['last_outbound']['status'] ?? '') === 'failed')
                                            <span class="badge badge-soft-danger fs-10">FAILED</span>
                                        @endif
                                    </small>
                                @else
                                    <span class="badge badge-soft-danger fs-11">None</span>
                                @endif
                            </td>
                            <td>
                                @if($d['expected_next_responder'] === 'concierge')
                                    <span class="badge badge-soft-danger"><i class="tio-alert-circle"></i> Concierge</span>
                                @elseif($d['expected_next_responder'] === 'human_agent')
                                    <span class="badge badge-soft-warning"><i class="tio-user"></i> Human Agent</span>
                                @else
                                    <span class="badge badge-soft-info"><i class="tio-time"></i> User</span>
                                @endif
                            </td>
                            <td>
                                <div class="fs-12 text-dark" style="max-width: 250px;">
                                    {{ $d['human_diagnosis'] }}
                                </div>
                                <small class="text-muted font-monospace fs-10">{{ $d['correlation_id'] }}</small>
                            </td>
                            <td>
                                @if($d['safety_classification'] === 'safe_auto')
                                    <span class="badge badge-safe-auto px-2 py-1">Safe Auto</span>
                                @elseif($d['safety_classification'] === 'safe_manual')
                                    <span class="badge badge-safe-manual px-2 py-1">Safe Manual</span>
                                @elseif($d['safety_classification'] === 'human_required')
                                    <span class="badge badge-human-required px-2 py-1">Human Required</span>
                                @elseif($d['safety_classification'] === 'code_defect')
                                    <span class="badge badge-code-defect px-2 py-1">Code Defect</span>
                                @else
                                    <span class="badge badge-healthy px-2 py-1">Normal</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <div class="d-flex justify-content-end gap-1">
                                    @if($d['recommended_action'] === 'renudge_current_step')
                                        <button class="btn btn-xs btn-outline-primary btn-quick-action" 
                                                data-id="{{ $c->id }}" 
                                                data-action="renudge_current_step" 
                                                data-title="Re-nudge Step Prompt"
                                                title="Re-send prompt for '{{ $c->current_step }}'">
                                            <i class="tio-refresh"></i> Nudge
                                        </button>
                                    @elseif($d['recommended_action'] === 'release_stale_handoff')
                                        <button class="btn btn-xs btn-outline-warning btn-quick-action" 
                                                data-id="{{ $c->id }}" 
                                                data-action="release_stale_handoff" 
                                                data-title="Release Stale Handoff"
                                                title="Return conversation to automation">
                                            <i class="tio-play"></i> Release
                                        </button>
                                    @endif
                                    <a href="{{ route('admin.whatsapp.operations-center.show', $c->id) }}" class="btn btn-xs btn-outline-secondary" title="Deep Diagnosis & History">
                                        <i class="tio-visible"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="text-center py-4 text-muted">
                                <i class="tio-smile fs-32 text-success d-block mb-2"></i>
                                No stuck or silenced conversations detected matching this filter.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        @if($conversations->hasPages())
            <div class="card-footer py-2">
                {{ $conversations->appends(request()->query())->links() }}
            </div>
        @endif
    </div>

    <!-- Recent Audits Log -->
    <div class="card mt-4">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0 fs-14"><i class="tio-history"></i> Recent Recovery Operations Audit Log</h5>
            <a href="{{ route('admin.whatsapp.operations-center.audits') }}" class="fs-12 text-primary">View Full Audit History &rarr;</a>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-nowrap card-table mb-0 fs-12">
                <thead class="thead-light">
                    <tr>
                        <th>Time</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Initiator</th>
                        <th>State Change</th>
                        <th>Status</th>
                        <th>Correlation ID</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentAudits as $audit)
                        <tr>
                            <td>{{ $audit->created_at->format('M d, H:i') }}</td>
                            <td>{{ $audit->conversation?->contact?->phone_number ?? 'N/A' }}</td>
                            <td><span class="font-monospace">{{ $audit->action }}</span></td>
                            <td><span class="badge badge-soft-secondary">{{ $audit->initiated_by }}</span></td>
                            <td>{{ $audit->previous_state }} &rarr; {{ $audit->proposed_state }}</td>
                            <td>
                                <span class="badge badge-soft-{{ $audit->status === 'success' ? 'success' : 'danger' }}">
                                    {{ strtoupper($audit->status) }}
                                </span>
                            </td>
                            <td><small class="text-muted font-monospace">{{ $audit->correlation_id }}</small></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-3 text-muted">No recovery audits recorded yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Preview / Dry Run Modal -->
<div class="modal fade" id="previewModal" tabindex="-1" aria-labelledby="previewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="previewModalLabel">Recovery Action Preview (Dry Run)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="previewModalBody">
                <div class="text-center py-3">
                    <div class="spinner-border text-primary" role="status"></div>
                    <div class="mt-2 text-muted">Validating eligibility and generating preview...</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnConfirmExecute" style="display: none;">
                    <i class="tio-checkmark-circle"></i> Confirm & Execute Recovery
                </button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('script_2')
<script>
$(document).ready(function() {
    // Select all checkboxes
    $('#selectAll').on('change', function() {
        $('.row-checkbox').prop('checked', this.checked);
        updateSelectedCount();
    });

    $('.row-checkbox').on('change', function() {
        updateSelectedCount();
    });

    function updateSelectedCount() {
        const count = $('.row-checkbox:checked').length;
        $('#selectedCount').text(count + ' selected');
    }

    let currentActionPayload = null;

    // Single Quick Action
    $('.btn-quick-action').on('click', function() {
        const id = $(this).data('id');
        const action = $(this).data('action');
        const title = $(this).data('title');

        currentActionPayload = {
            isBulk: false,
            id: id,
            action: action
        };

        $('#previewModalLabel').text('Preview: ' + title);
        $('#btnConfirmExecute').hide();
        $('#previewModal').modal('show');

        $.ajax({
            url: '{{ url("admin/whatsapp/operations-center/conversation") }}/' + id + '/preview',
            type: 'POST',
            data: {
                _token: '{{ csrf_token() }}',
                action: action
            },
            success: function(res) {
                let html = '<div class="alert alert-info py-2"><strong>Dry Run Verification:</strong> ' + (res.message || res.status) + '</div>';
                html += '<table class="table table-sm table-bordered fs-13">';
                html += '<tr><th width="30%">Conversation ID</th><td>#' + res.conversation_id + ' (' + res.phone + ')</td></tr>';
                html += '<tr><th>Action</th><td class="font-monospace">' + res.action + '</td></tr>';
                if (res.proposed_state) {
                    html += '<tr><th>State Transition</th><td>' + res.previous_state + ' &rarr; <strong>' + res.proposed_state + '</strong></td></tr>';
                }
                if (res.step) {
                    html += '<tr><th>Current Step Prompt</th><td class="font-monospace text-primary">' + res.step + '</td></tr>';
                }
                html += '<tr><th>Service Window Open</th><td>' + (res.service_window_open ? '<span class="text-success">YES (Direct message allowed)</span>' : '<span class="text-danger">NO (Meta approved template required)</span>') + '</td></tr>';
                html += '<tr><th>Eligibility Status</th><td><span class="badge badge-success">' + res.status + '</span></td></tr>';
                html += '<tr><th>Correlation Trace ID</th><td class="font-monospace">' + res.correlation_id + '</td></tr>';
                html += '</table>';

                $('#previewModalBody').html(html);
                if (res.status === 'dry_run_passed') {
                    $('#btnConfirmExecute').show();
                }
            },
            error: function(xhr) {
                $('#previewModalBody').html('<div class="alert alert-danger">Preview failed: ' + (xhr.responseJSON?.message || 'Server error') + '</div>');
            }
        });
    });

    // Bulk Preview
    $('#btnPreviewBulk').on('click', function() {
        const action = $('#bulkActionSelect').val();
        if (!action) {
            toastr.warning('Please choose an action first.');
            return;
        }

        const selectedIds = $('.row-checkbox:checked').map(function() {
            return $(this).val();
        }).get();

        if (selectedIds.length === 0) {
            toastr.warning('Please select at least one conversation.');
            return;
        }

        currentActionPayload = {
            isBulk: true,
            ids: selectedIds,
            action: action
        };

        $('#previewModalLabel').text('Bulk Dry Run: ' + action + ' (' + selectedIds.length + ' targets)');
        $('#btnConfirmExecute').hide();
        $('#previewModal').modal('show');

        $.ajax({
            url: '{{ route("admin.whatsapp.operations-center.bulk.preview") }}',
            type: 'POST',
            data: {
                _token: '{{ csrf_token() }}',
                conversation_ids: selectedIds,
                action: action
            },
            success: function(res) {
                let html = '<div class="alert alert-info py-2 mb-3"><strong>Dry Run Summary:</strong> ' + res.eligible_count + ' eligible, ' + res.excluded_count + ' excluded out of ' + res.total_selected + ' selected.</div>';
                html += '<div class="table-responsive" style="max-height: 300px;"><table class="table table-sm table-bordered fs-12">';
                html += '<thead class="thead-light"><tr><th>ID</th><th>Phone</th><th>Step</th><th>Status</th><th>Dry Run Output</th></tr></thead><tbody>';
                
                res.items.forEach(function(item) {
                    const isPassed = item.status === 'dry_run_passed';
                    html += '<tr class="' + (isPassed ? '' : 'table-warning') + '">';
                    html += '<td>#' + item.id + '</td>';
                    html += '<td>' + (item.phone || '-') + '</td>';
                    html += '<td>' + (item.step || '-') + '</td>';
                    html += '<td><span class="badge badge-' + (isPassed ? 'success' : 'secondary') + '">' + item.status + '</span></td>';
                    html += '<td>' + (item.message || item.reason || '-') + '</td>';
                    html += '</tr>';
                });
                
                html += '</tbody></table></div>';
                $('#previewModalBody').html(html);

                if (res.eligible_count > 0) {
                    $('#btnConfirmExecute').show().text('Confirm & Execute (' + res.eligible_count + ' Eligible)');
                }
            },
            error: function(xhr) {
                $('#previewModalBody').html('<div class="alert alert-danger">Bulk preview failed: ' + (xhr.responseJSON?.message || 'Server error') + '</div>');
            }
        });
    });

    // Execute Confirmed Recovery
    $('#btnConfirmExecute').on('click', function() {
        if (!currentActionPayload) return;

        const btn = $(this);
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Executing...');

        if (currentActionPayload.isBulk) {
            $.ajax({
                url: '{{ route("admin.whatsapp.operations-center.bulk.execute") }}',
                type: 'POST',
                data: {
                    _token: '{{ csrf_token() }}',
                    conversation_ids: currentActionPayload.ids,
                    action: currentActionPayload.action
                },
                success: function() {
                    $('#previewModal').modal('hide');
                    window.location.reload();
                },
                error: function(xhr) {
                    btn.prop('disabled', false).text('Confirm & Execute');
                    toastr.error(xhr.responseJSON?.message || 'Execution failed.');
                }
            });
        } else {
            $.ajax({
                url: '{{ url("admin/whatsapp/operations-center/conversation") }}/' + currentActionPayload.id + '/execute',
                type: 'POST',
                data: {
                    _token: '{{ csrf_token() }}',
                    action: currentActionPayload.action
                },
                success: function() {
                    $('#previewModal').modal('hide');
                    window.location.reload();
                },
                error: function(xhr) {
                    btn.prop('disabled', false).text('Confirm & Execute');
                    toastr.error(xhr.responseJSON?.message || 'Execution failed.');
                }
            });
        }
    });
});
</script>
@endpush
