@extends('layouts.admin.app')

@section('title', 'Resume Campaigns')

@section('content')
<div class="content container-fluid">
    <div class="page-header d-flex justify-content-between align-items-center">
        <h1 class="page-header-title">Bulk Resume Campaigns</h1>
        <a href="{{ route('admin.whatsapp.resume-campaigns.create') }}" class="btn btn-primary">Create Campaign</a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="card">
        <div class="table-responsive">
            <table class="table table-borderless table-thead-bordered table-nowrap table-align-middle card-table">
                <thead class="thead-light">
                    <tr>
                        <th>Campaign Name</th>
                        <th>Meta Template</th>
                        <th>Status</th>
                        <th>Sent</th>
                        <th>Resumed</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($campaigns as $campaign)
                    <tr>
                        <td>{{ $campaign->name }}</td>
                        <td>{{ $campaign->meta_template_name }}</td>
                        <td>
                            <span class="badge badge-soft-{{ $campaign->status === 'active' ? 'success' : ($campaign->status === 'draft' ? 'secondary' : 'info') }}">
                                {{ ucfirst($campaign->status) }}
                            </span>
                        </td>
                        <td>{{ $campaign->sent_count }}</td>
                        <td>{{ $campaign->resumed_count }}</td>
                        <td>{{ $campaign->created_at->format('Y-m-d H:i') }}</td>
                        <td>
                            @if($campaign->status === 'draft')
                                <form action="{{ route('admin.whatsapp.resume-campaigns.launch', $campaign->id) }}" method="POST" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Launch this campaign?');">Launch</button>
                                </form>
                            @else
                                <span class="text-muted">No further actions</span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="text-center">No campaigns created yet.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            {{ $campaigns->links() }}
        </div>
    </div>
</div>
@endsection
