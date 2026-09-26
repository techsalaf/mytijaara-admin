@extends('layouts.admin.app')
@section('title','Product listing drafts')
@section('content')
<div class="content container-fluid"><h1>Product listing drafts</h1><p>Follow vendor progress and recover incomplete listings. No action here publishes a product.</p>
<form class="mb-3"><label for="status">Draft status</label><select id="status" name="status" class="form-control" style="max-width:260px"><option value="">All</option>active</option><option>saved</option><option>completed</option><option>cancelled</option></select><button class="btn btn-primary mt-2">Filter</button></form>
<div class="card table-responsive"><table class="table"><thead><tr><th>Shop / module</th><th>Status</th><th>Step</th><th>Progress</th><th>Last activity</th><th>Attention</th></tr></thead><tbody>
@forelse($drafts as $d)<tr><td><a href="{{route('admin.whatsapp.product-drafts.show',$d)}}">{{ $d->store?->name ?? 'Unavailable shop' }}</a><br>{{ $d->store?->module?->module_name }}</td><td>{{ $d->status }}</td><td>{{ str_replace('_',' ',$d->step) }}</td><td>{{ round(count(array_intersect(array_keys($d->data),app(\Modules\WhatsAppVendorConcierge\app\Services\ProductFieldMap::class)->steps($d->store)))/max(1,count(app(\Modules\WhatsAppVendorConcierge\app\Services\ProductFieldMap::class)->steps($d->store))-1)*100) }}%</td><td>{{ $d->updated_at }}</td><td>{{ $d->needs_attention ? 'Needs assistance' : '—' }}</td></tr>@empty<tr><td colspan="6">No product drafts yet.</td></tr>@endforelse
</tbody></table></div>{{ $drafts->links() }}
<h2 class="mt-4">Categories missing images</h2><p>Add images through the normal category admin screen. These records are not visually launch-ready yet.</p>
<div class="card table-responsive"><table class="table"><thead><tr><th>ID</th><th>Category</th><th>Module ID</th><th>Parent ID</th></tr></thead><tbody>@foreach($missing as $c)<tr><td>{{ $c->id }}</td><td>{{ $c->name }}</td><td>{{ $c->module_id }}</td><td>{{ $c->parent_id }}</td></tr>@endforeach</tbody></table></div>{{ $missing->links() }}</div>
@endsection
