@extends('layouts.admin.app')

@section('title', 'Incomplete & Stuck Applications')

@section('content')
<div class="content container-fluid">
    <div class="page-header">
        <h1 class="page-header-title">Incomplete & Stuck Applications</h1>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="card mb-3">
        <div class="card-body">
            <form action="{{ route('admin.whatsapp.stuck-applications.index') }}" method="GET">
                <div class="row align-items-center">
                    <div class="col-md-4">
                        <select name="status" class="form-control" onchange="this.form.submit()">
                            <option value="">All Incomplete Statuses</option>
                            @foreach($statuses as $key => $label)
                                <option value="{{ $key }}" {{ request('status') === $key ? 'selected' : '' }}>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-borderless table-thead-bordered table-nowrap table-align-middle card-table">
                <thead class="thead-light">
                    <tr>
                        <th>ID</th>
                        <th>Contact</th>
                        <th>Status</th>
                        <th>Current Step</th>
                        <th>Last Activity</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($applications as $app)
                    <tr>
                        <td>#{{ $app->id }}</td>
                        <td>{{ $app->contact->name ?? 'Unknown' }} ({{ $app->contact->phone ?? 'N/A' }})</td>
                        <td>
                            <span class="badge badge-soft-{{ $app->status === 'stuck' ? 'danger' : 'warning' }}">
                                {{ $statuses[$app->status] ?? $app->status }}
                            </span>
                        </td>
                        <td>{{ $app->current_step ?? 'N/A' }}</td>
                        <td>{{ $app->last_activity_at ? $app->last_activity_at->diffForHumans() : 'N/A' }}</td>
                        <td>
                            <form action="{{ route('admin.whatsapp.stuck-applications.recover', $app->id) }}" method="POST" class="d-inline">
                                @csrf
                                <select name="action" class="form-control form-control-sm d-inline-block w-auto" required>
                                    <option value="">Select Action...</option>
                                    <option value="retry_failed">Retry Failed Processing</option>
                                    <option value="restore_state">Restore Last Valid State</option>
                                    <option value="send_prompt">Send Resume Prompt</option>
                                </select>
                                <button type="submit" class="btn btn-sm btn-primary">Execute</button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="text-center">No stuck applications found.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            {{ $applications->links() }}
        </div>
    </div>
</div>
@endsection
