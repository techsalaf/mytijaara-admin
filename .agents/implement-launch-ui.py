from pathlib import Path
def put(p,s):
 p=Path(p);p.parent.mkdir(parents=True,exist_ok=True);p.write_text(s,encoding='utf-8')
put('Modules/WhatsAppVendorConcierge/app/Http/Controllers/Admin/StoreDeliveryPolicyController.php',r'''<?php
namespace Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Services\StoreManagedDeliveryPolicy;
use Illuminate\Http\Request;
class StoreDeliveryPolicyController extends Controller
{
    public function index()
    {
        abort_unless((int)auth('admin')->user()?->role_id === 1,403);
        return view('whatsappvendorconcierge::admin.operations_center.delivery-policy',['mode'=>StoreManagedDeliveryPolicy::mode()]);
    }
    public function update(Request $request, StoreManagedDeliveryPolicy $policy)
    {
        abort_unless((int)auth('admin')->user()?->role_id === 1,403);
        $request->validate(['enabled'=>'required|boolean','confirmed'=>'accepted']);
        $before=StoreManagedDeliveryPolicy::mode();
        $count=$policy->apply($request->boolean('enabled'));
        \Modules\WhatsAppVendorConcierge\app\Models\ConciergeRecoveryAudit::create([
            'action'=>'global_store_managed_delivery','initiated_by'=>'admin','admin_id'=>auth('admin')->id(),
            'is_dry_run'=>false,'status'=>'success','reason'=>'Administrator confirmed global delivery policy',
            'details'=>['previous'=>$before,'enabled'=>$request->boolean('enabled'),'stores_updated'=>$count],
            'correlation_id'=>'DELIVERY-'.\Illuminate\Support\Str::uuid()]);
        return back()->with('delivery_saved',true);
    }
}
''')
put('Modules/WhatsAppVendorConcierge/resources/views/admin/operations_center/delivery-policy.blade.php',r'''@extends('layouts.admin.app')
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
''')
p=Path('Modules/WhatsAppVendorConcierge/routes/web.php');s=p.read_text();s+='''
Route::middleware(['web','admin', \\Modules\\WhatsAppVendorConcierge\\app\\Http\\Middleware\\AuthorizeWhatsAppOperations::class])->group(function () {
    Route::get('/admin/whatsapp/store-managed-delivery', [\\Modules\\WhatsAppVendorConcierge\\app\\Http\\Controllers\\Admin\\StoreDeliveryPolicyController::class,'index'])->name('admin.whatsapp.delivery-policy.index');
    Route::post('/admin/whatsapp/store-managed-delivery', [\\Modules\\WhatsAppVendorConcierge\\app\\Http\\Controllers\\Admin\\StoreDeliveryPolicyController::class,'update'])->name('admin.whatsapp.delivery-policy.update');
});
''';p.write_text(s)
p=Path('Modules/WhatsAppVendorConcierge/resources/views/admin/operations_center/index.blade.php');s=p.read_text().replace('<div class="ops-actions"><a','<div class="ops-actions">@if((int)auth("admin")->user()?->role_id === 1)<a class="btn btn-outline-primary" href="{{ route("admin.whatsapp.delivery-policy.index") }}">Store-managed delivery</a>@endif<a',1);p.write_text(s)
p=Path('Modules/WhatsAppVendorConcierge/app/Http/Middleware/InjectAdminSidebarMenu.php');s=p.read_text().replace('// Operations Centre','''const deliveryLink = document.createElement('a');
                deliveryLink.className = 'v2-nav-item';
                deliveryLink.href = '/admin/whatsapp/store-managed-delivery';
                deliveryLink.innerHTML = '<span class="v2-dot v2-dot--green"></span><span class="v2-label">Store-managed Delivery</span>';
                if (!document.getElementById('global-delivery-nav')) { deliveryLink.id='global-delivery-nav'; vendorContainer.appendChild(deliveryLink); }
                // Operations Centre''',1);p.write_text(s)
