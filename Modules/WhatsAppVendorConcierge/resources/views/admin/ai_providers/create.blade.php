@extends('layouts.admin.app')

@section('title', 'Add AI Provider')

@section('content')
<div class="content container-fluid">
    <div class="page-header">
        <h1 class="page-header-title">
            <span class="page-header-icon">
                <i class="tio-add-circle"></i>
            </span>
            <span>{{translate('Add_AI_Provider')}}</span>
        </h1>
    </div>

    <div class="row g-3">
        <div class="col-sm-12">
            <div class="card">
                <div class="card-body">
                    <form action="{{route('admin.whatsapp.ai-providers.store')}}" method="post">
                        @csrf
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="input-label" for="name">{{translate('Name')}} (e.g. DeepSeek, OpenAI)</label>
                                    <input type="text" name="name" class="form-control" placeholder="DeepSeek" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="input-label" for="driver">{{translate('Driver')}}</label>
                                    <select name="driver" class="form-control" required>
                                        <option value="openai">OpenAI Compatible (Most Common)</option>
                                        <option value="anthropic">Anthropic</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="input-label" for="priority">{{translate('Priority')}} (0 is highest)</label>
                                    <input type="number" name="priority" class="form-control" value="0" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="input-label" for="base_url">{{translate('Base URL')}} <small class="text-danger">(Required for DeepSeek, Kiro, etc.)</small></label>
                                    <input type="url" name="base_url" class="form-control" placeholder="https://api.deepseek.com/v1">
                                    <small class="form-text text-muted">Leave blank if using actual OpenAI or Anthropic.</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="input-label" for="model">{{translate('Model Name')}}</label>
                                    <input type="text" name="model" class="form-control" placeholder="deepseek-chat" required>
                                </div>
                            </div>
                            <div class="col-md-12">
                                <div class="form-group">
                                    <label class="input-label" for="api_key">{{translate('API Key')}}</label>
                                    <input type="text" name="api_key" class="form-control" placeholder="sk-..." required>
                                </div>
                            </div>
                        </div>

                        <div class="btn--container justify-content-end">
                            <button type="reset" class="btn btn--reset">{{translate('reset')}}</button>
                            <button type="submit" class="btn btn--primary">{{translate('submit')}}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
