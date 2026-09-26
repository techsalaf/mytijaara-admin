@extends('layouts.admin.app')
@section('title','Store-managed delivery')
@section('content')
<div class="content container-fluid" style="max-width:900px">
<h1 class="page-header-title">Store-managed delivery</h1>
<p class="text-muted">Choose who handles delivery across Grocery, Shop, Food and Pharmacy.</p>
@if(session('delivery_saved'))<div class="alert alert-success">The global delivery policy has been saved.</div>@endif
@if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
<div class="card"><div class="card-body">
<p><strong>Current policy:</strong> {{ $mode === null ? 'Individual store settings' : ($mode ? 'Stores arrange delivery' : 'Platform delivery') }}</p>
<form method="POST" action="{{ route('admin.whatsapp.delivery-policy.update') }}">@csrf
<label for="enabled">Global delivery policy</label>
<select class="form-control mb-3" id="enabled" name="enabled"><option value="1" @selected($mode===1)>ON — vendors arrange their own delivery</option><option value="0" @selected($mode===0)>OFF — use platform delivery</option></select>
<p>This applies to existing and future stores, including subscription stores. Parcel and rental modules are excluded. External couriers do not provide platform rider tracking. Vendors must still update order status and meet delivery verification requirements.</p>
<div class="alert alert-warning">Before turning this off, make sure platform riders and order confirmation are ready. With no platform riders, delivery orders may stall.</div>
<label class="d-flex align-items-start mb-3"><input type="checkbox" name="confirmed" value="1" required class="mr-2 mt-1">I understand this changes delivery responsibility for all supported stores.</label>
<button class="btn btn-primary">Save global policy</button><a class="btn btn-outline-secondary ml-2" href="{{ route('admin.whatsapp.operations-center.index') }}">Back</a>
</form></div></div></div>
@endsection
