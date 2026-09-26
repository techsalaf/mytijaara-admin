@extends('layouts.admin.app')
@section('title','Product draft')
@section('content')
<div class="content container-fluid" style="max-width:1100px"><h1>{{ $draft->store->name }} — draft #{{ $draft->id }}</h1>
<p>{{ $draft->store->module->module_name }} · {{ $draft->status }} · {{ str_replace('_',' ',$draft->step) }} · Updated {{ $draft->updated_at }}</p>
@if(session('saved'))<div class="alert alert-success">Action completed.</div>@endif
@if($draft->needs_attention)<div class="alert alert-warning">Needs assistance: {{ $draft->stalled_turns }} turns without progress. Draft details are preserved.</div>@endif
<a class="btn btn-outline-primary mb-3" href="{{route('admin.whatsapp.inbox.show',$draft->conversation_id)}}">Open conversation / assign human</a>
@if(!empty($draft->data['media_id']))<div><img src="{{route('admin.whatsapp.product-drafts.image',$draft)}}" alt="Vendor product photo" style="max-width:240px;max-height:240px;object-fit:contain"></div>@endif
<div class="row"><div class="col-md-6"><h2>Collected details</h2><dl>@foreach($draft->data as $field=>$value)<dt>{{ str_replace('_',' ',$field) }}</dt><dd>{{is_array($value)?json_encode($value):($value??'Not provided')}} <small class="text-muted">{{ $draft->sources[$field]??'Vendor' }}</small></dd>@endforeach</dl><p>Final product: {{ $draft->item_id??'Not created' }}</p></div>
<div class="col-md-6"><h2>Image analysis</h2><pre style="white-space:pre-wrap;overflow-wrap:anywhere">{{json_encode($draft->vision,JSON_PRETTY_PRINT)}}</pre><h2>Validation feedback</h2><pre style="white-space:pre-wrap">{{json_encode($draft->errors,JSON_PRETTY_PRINT)}}</pre></div></div>
<h2>Recent messages</h2>@foreach($messages as $message)<p><strong>{{$message->direction}} · {{$message->created_at}}</strong><br>{{ $message->raw_text ?: data_get($message->content,'text.body',data_get($message->content,'text','Media / interactive message')) }}</p>@endforeach
@if(in_array($draft->status,['active','saved']))<form method="POST" action="{{route('admin.whatsapp.product-drafts.action',$draft)}}">@csrf<label for="action">Recovery action</label><select id="action" name="action" class="form-control"><option value="resume">Resume deterministic flow</option><option value="without_ai">Continue without AI</option><option value="retry_vision">Retry image analysis</option><option value="resend">Re-send current prompt (open reply window required)</option><option value="cancel">Cancel stale draft</option></select><label class="mt-3"><input type="checkbox" name="confirmed" value="1" required> I confirm this action on this vendor's draft.</label><br><button class="btn btn-primary">Apply action</button></form>@endif
</div>
@endsection
