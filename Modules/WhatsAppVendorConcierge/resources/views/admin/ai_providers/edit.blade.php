@extends('layouts.admin.app')

@section('title', 'Update AI Provider')

@section('content')
<div class="content container-fluid">
    <div class="page-header">
        <h1 class="page-header-title">
            <span class="page-header-icon">
                <i class="tio-edit"></i>
            </span>
            <span>{{translate('Update_AI_Provider')}}</span>
        </h1>
    </div>

    <div class="row g-3">
        <div class="col-sm-12">
            <div class="card">
                <div class="card-body">
                    <form action="{{route('admin.whatsapp.ai-providers.update', [$aiProvider->id])}}" method="post">
                        @csrf @method('PUT')
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="input-label" for="name">{{translate('Name')}}</label>
                                    <input type="text" name="name" class="form-control" value="{{$aiProvider->name}}" readonly>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="input-label" for="driver">{{translate('Driver')}}</label>
                                    <input type="text" name="driver" class="form-control" value="{{$aiProvider->driver}}" readonly>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="input-label" for="priority">{{translate('Priority')}} (0 is highest)</label>
                                    <input type="number" name="priority" class="form-control" value="{{$aiProvider->priority}}" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="input-label" for="base_url">{{translate('Base URL')}}</label>
                                    <input type="url" name="base_url" class="form-control" value="{{$aiProvider->base_url}}" readonly>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="input-label" for="model">{{translate('Model Name')}}</label>
                                    <input type="text" name="model" class="form-control" value="{{$aiProvider->model}}" readonly>
                                </div>
                            </div>
                            <div class="col-md-12">
                                <div class="form-group">
                                    <label class="input-label" for="api_key">{{translate('API Key')}} <small class="text-danger">(Leave blank to keep existing)</small></label>
                                    <input type="text" name="api_key" class="form-control" placeholder="sk-...">
                                </div>
                            </div>
                        </div>

                        <div class="btn--container justify-content-end">
                            <button type="submit" class="btn btn--primary">{{translate('Update')}}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

