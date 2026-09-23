@extends('layouts.admin.app')

@section('title', 'AI Providers Management')

@section('content')
<div class="content container-fluid">
    <!-- Page Header -->
    <div class="page-header">
        <h1 class="page-header-title">
            <span class="page-header-icon">
                <i class="tio-bot"></i>
            </span>
            <span>{{translate('AI_Providers')}}</span>
        </h1>
        <p class="text-muted">Manage fallback AI APIs (OpenAI, DeepSeek, Gemini, Kiro, etc.) for the WhatsApp Concierge.</p>
    </div>
    <!-- End Page Header -->

    <div class="row g-3">
        <div class="col-sm-12">
            <div class="card">
                <div class="card-header border-0 py-2">
                    <div class="search--button-wrapper">
                        <h5 class="card-title">
                            {{translate('AI_Providers_List')}} <span class="badge badge-soft-dark ml-2">{{$providers->count()}}</span>
                        </h5>
                    </div>
                </div>
                <!-- Table -->
                <div class="table-responsive datatable-custom">
                    <table class="table table-borderless table-thead-bordered table-nowrap table-align-middle card-table">
                        <thead class="thead-light">
                        <tr>
                            <th>{{translate('SL')}}</th>
                            <th>{{translate('Priority')}}</th>
                            <th>{{translate('Name')}}</th>
                            <th>{{translate('Driver')}}</th>
                            <th>{{translate('Model')}}</th>
                            <th>{{translate('Status')}}</th>
                            <th class="text-center">{{translate('Action')}}</th>
                        </tr>
                        </thead>

                        <tbody>
                        @foreach($providers as $key => $provider)
                            <tr>
                                <td>{{$key + 1}}</td>
                                <td><span class="badge badge-soft-info">{{$provider->priority}}</span></td>
                                <td>{{$provider->name}}</td>
                                <td><code>{{$provider->driver}}</code></td>
                                <td><code>{{$provider->model}}</code></td>
                                <td>
                                    <label class="toggle-switch toggle-switch-sm" for="statusCheckbox{{$provider->id}}">
                                        <input type="checkbox" onclick="location.href='{{route('admin.whatsapp.ai-providers.toggle', [$provider['id']])}}'" class="toggle-switch-input" id="statusCheckbox{{$provider->id}}" {{$provider->is_active ? 'checked' : ''}}>
                                        <span class="toggle-switch-label">
                                            <span class="toggle-switch-indicator"></span>
                                        </span>
                                    </label>
                                    @if($provider->status !== 'working')
                                        <span class="badge badge-soft-danger ml-2">{{$provider->status}}</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="btn--container justify-content-center">
                                        <a class="btn action-btn btn--primary btn-outline-primary" href="{{route('admin.whatsapp.ai-providers.edit', [$provider['id']])}}">
                                            <i class="tio-edit"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    @if(count($providers) === 0)
                        <div class="empty--data">
                            <img src="{{asset('/public/assets/admin/svg/illustrations/sorry.svg')}}" alt="public">
                            <h5>{{translate('no_data_found')}}</h5>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
