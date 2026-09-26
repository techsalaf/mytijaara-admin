<?php
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
