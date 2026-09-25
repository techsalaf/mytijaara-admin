@extends('layouts.admin.app')

@section('title', 'Create Resume Campaign')

@section('content')
<div class="content container-fluid">
    <div class="page-header">
        <h1 class="page-header-title">Create Resume Campaign</h1>
    </div>

    <div class="card">
        <div class="card-body">
            <form action="{{ route('admin.whatsapp.resume-campaigns.store') }}" method="POST">
                @csrf
                <div class="form-group mb-3">
                    <label for="name">Campaign Name</label>
                    <input type="text" name="name" id="name" class="form-control" required placeholder="e.g., September Stuck Users">
                </div>

                <div class="form-group mb-3">
                    <label for="meta_template_name">Meta Template Name</label>
                    <input type="text" name="meta_template_name" id="meta_template_name" class="form-control" required placeholder="e.g., vendor_resume_prompt">
                    <small class="form-text text-muted">Must be an approved Meta template since users are likely outside the 24-hour service window.</small>
                </div>

                <div class="form-group mb-3">
                    <label>Audience Selection (Target Statuses)</label>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="audience_status[]" value="stuck" id="status_stuck" checked>
                        <label class="form-check-label" for="status_stuck">Stuck</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="audience_status[]" value="abandoned" id="status_abandoned" checked>
                        <label class="form-check-label" for="status_abandoned">Abandoned</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="audience_status[]" value="temporarily_failed" id="status_failed">
                        <label class="form-check-label" for="status_failed">Temporarily Failed</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="audience_status[]" value="waiting_for_user" id="status_waiting">
                        <label class="form-check-label" for="status_waiting">Waiting for User</label>
                    </div>
                </div>

                <div class="mt-4">
                    <button type="submit" class="btn btn-primary">Save Campaign Draft</button>
                    <a href="{{ route('admin.whatsapp.resume-campaigns.index') }}" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
