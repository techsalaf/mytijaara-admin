@extends('layouts.admin.app')
@section('title', 'Concierge Operations Centre')
@push('css_or_js')
<style>
.ops-header,.ops-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.ops-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin:20px 0}.ops-stat{padding:18px;border:1px solid #dfe5eb;border-radius:10px;background:white;color:#334155;display:block}.ops-stat strong{display:block;font-size:28px;color:#133b37;margin:6px 0}.ops-stat:hover{border-color:#008069;text-decoration:none}.ops-description{max-width:340px;white-space:normal;line-height:1.5}.ops-table td{vertical-align:middle}.ops-actions{display:flex;flex-wrap:wrap;gap:6px}.ops-toolbar{padding:16px}.ops-toolbar form{display:flex;gap:8px;flex-wrap:wrap}.ops-muted{color:#64748b;font-size:13px}.ops-empty{text-align:center;padding:40px!important}.ops-result{padding:12px;border-bottom:1px solid #e5e7eb;overflow-wrap:anywhere}@media(max-width:700px){.ops-summary{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.ops-header h1{font-size:22px}.ops-toolbar>*{width:100%}.ops-toolbar input,.ops-toolbar select{width:100%!important}.ops-stat{padding:12px}.ops-toolbar .btn{white-space:normal}}
</style>
@endpush
@section('content')
<div class="content container-fluid">
 <div class="ops-header">
  <div><h1 class="page-header-title">Concierge Operations Centre</h1><p class="text-muted mb-0">See who needs help, understand why, and preview the next action.</p></div>
  <div class="ops-actions"><a class="btn btn-outline-primary" href="{{ route('admin.whatsapp.inbox.index') }}">Open inbox</a><a class="btn btn-outline-secondary" href="{{ route('admin.whatsapp.operations-center.audits') }}">Recovery history</a>
   <form method="POST" action="{{ route('admin.whatsapp.operations-center.health-check') }}">@csrf<button class="btn btn-primary">Refresh health scan</button></form></div>
 </div>
 <p class="ops-muted mt-3">Last saved scan: {{ $latestHealthCheck?->created_at?->diffForHumans() ?? 'Not run yet' }}. Counts below use current conversation records. A reply recorded as sent means Meta accepted it; delivery and read receipts are shown separately.</p>
 <div class="ops-summary">
 @foreach([
 ['active','Registrations in progress','total_active_onboarding','Incomplete applications'],
 ['waiting_concierge','Waiting for a reply','waiting_for_concierge','No successful reply recorded'],
 ['waiting_user','Waiting for the customer','waiting_for_user','No repair is needed'],
 ['human_handoff','Human support','in_human_handoff','Review the open support request'],
 ['stale_handoff','Orphaned handoffs','stale_human_handoff','No open support ticket'],
 ['failed','Failed replies','failed_outbounds','Inspect before retrying'],
 ['technical','Technical investigation','issues_requiring_code','Not safe to repair automatically'],
 ['pending','Awaiting approval','vendors_awaiting_approval','Review stores across modules']
 ] as [$key,$label,$metric,$hint])
 <a class="ops-stat" href="{{ $key === 'pending' ? route('admin.store.pending-requests') : route('admin.whatsapp.operations-center.index',['filter'=>$key]) }}"><span>{{ $label }}</span><strong>{{ $overview[$metric] }}</strong><small>{{ $hint }}</small></a>
 @endforeach
 </div>
 <div class="card">
  <div class="ops-toolbar border-bottom">
   <div><h2 class="h4 mb-1">{{ ucfirst(str_replace('_',' ',$filter)) }} conversations <span class="badge badge-light">{{ $conversations->total() }}</span></h2><span class="ops-muted">Read the diagnosis before selecting an action.</span></div>
   <form method="GET"><label class="sr-only" for="ops-filter">Show conversations</label><select class="form-control" id="ops-filter" name="filter">
    @foreach(['all'=>'All conversations','active'=>'Registrations in progress','waiting_concierge'=>'Waiting for reply','waiting_user'=>'Waiting for customer','human_handoff'=>'Human support','stale_handoff'=>'Orphaned handoffs','stuck'=>'Needs attention','failed'=>'Failed replies','technical'=>'Technical investigation'] as $value=>$label)<option value="{{ $value }}" @selected($filter===$value)>{{ $label }}</option>@endforeach
   </select><label class="sr-only" for="ops-search">Search name or phone</label><input class="form-control" id="ops-search" name="search" value="{{ $search }}" placeholder="Search name or phone"><button class="btn btn-outline-primary">Search</button><a class="btn btn-link" href="{{ route('admin.whatsapp.operations-center.index') }}">Clear</a></form>
  </div>
  <div class="ops-toolbar bg-light"><label class="mb-0"><input type="checkbox" id="selectAll"> Select this page · <span id="selectedCount">0</span> selected</label><div class="ops-actions"><select class="form-control" id="bulkAction" style="width:230px" aria-label="Recovery action"><option value="renudge_current_step">Resend current question</option><option value="release_stale_handoff">Resume orphaned handoff</option><option value="assign_human">Request human support</option></select><button class="btn btn-primary" id="previewSelected">Preview selected</button></div></div>
  <div class="table-responsive"><table class="table ops-table mb-0"><thead><tr><th></th><th>Customer</th><th>Progress</th><th>Who responds next?</th><th>What happened?</th><th>Next action</th></tr></thead><tbody>
  @forelse($diagnosedItems as $item)
  @php($c=$item['conversation']) @php($d=$item['diag'])
  <tr><td><input class="row-checkbox" type="checkbox" value="{{ $c->id }}" aria-label="Select {{ $d['name'] }}"></td><td><a href="{{ route('admin.whatsapp.inbox.show',$c->id) }}"><strong>{{ $d['name'] }}</strong></a><div class="ops-muted">{{ $d['phone'] }}</div><small>#{{ $c->id }}</small></td>
   <td>{{ $d['step_label'] }}<div class="ops-muted">{{ $d['progress_percentage'] }}% · {{ ucfirst(str_replace('_',' ',$d['state'])) }}</div></td>
   <td>{{ ['user'=>'Customer','concierge'=>'Concierge','human_agent'=>'Support team','admin'=>'Store reviewer'][$d['expected_next_responder']] ?? $d['expected_next_responder'] }}<div class="ops-muted">{{ $d['service_window_open'] ? 'Reply window open' : 'Approved template needed to message' }}</div></td>
   <td class="ops-description">{{ $d['human_diagnosis'] }}<div class="ops-muted mt-1">Customer: {{ $d['last_inbound']['readable_time'] ?? 'No message' }}<br>Reply: {{ $d['last_outbound']['readable_time'] ?? 'No recorded reply' }}</div></td>
   <td><div class="ops-actions"><a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.whatsapp.operations-center.show',$c->id) }}">Details</a><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.whatsapp.inbox.show',$c->id) }}">Chat</a>
    @if(in_array($d['recommended_action'],['renudge_current_step','release_stale_handoff','assign_human']))<button class="btn btn-sm btn-primary preview-one" data-id="{{ $c->id }}" data-action="{{ $d['recommended_action'] }}">Preview help</button>@endif
    @if($d['recommended_action']==='send_resume_template')<a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.whatsapp.resume-campaigns.index') }}">Resume templates</a>@endif
   </div></td></tr>
  @empty<tr><td colspan="6" class="ops-empty"><strong>No conversations match these filters.</strong><div class="text-muted mt-2">Clear the search or choose another group. This does not mean every conversation is healthy.</div></td></tr>@endforelse
  </tbody></table></div>
  <div class="card-footer">{{ $conversations->links() }}</div>
 </div>
 <p class="ops-muted mt-3">Recovery never approves a store, discards a draft, or closes an open support ticket. Historical inbound messages cannot be replayed safely without a processing checkpoint.</p>
</div>
<div class="modal fade" id="recoveryPreview" tabindex="-1" role="dialog" aria-labelledby="recoveryTitle" aria-hidden="true"><div class="modal-dialog modal-lg" role="document"><div class="modal-content">
 <div class="modal-header"><h2 class="h4 modal-title" id="recoveryTitle">Review recovery</h2><button class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>
 <div class="modal-body" id="previewBody" aria-live="polite"></div><div class="modal-footer"><button class="btn btn-secondary" data-dismiss="modal">Cancel</button><button class="btn btn-primary" id="confirmRecovery" disabled>Confirm recovery</button></div>
</div></div></div>
@endsection
@push('script_2')
<script>
$(function(){
 let pending=null;
 const escape = value => $('<div>').text(value == null ? '' : String(value)).html();
 const csrf = @json(csrf_token());
 const updateCount=()=>$('#selectedCount').text($('.row-checkbox:checked').length);
 $('#selectAll').on('change',function(){$('.row-checkbox').prop('checked',this.checked);updateCount();});$('.row-checkbox').on('change',updateCount);
 function preview(ids,action){
  if(!ids.length){toastr.warning('Select at least one conversation.');return;}
  pending=null;$('#confirmRecovery').prop('disabled',true);$('#previewBody').text('Checking current eligibility…');$('#recoveryPreview').modal('show');
  $.ajax({url:@json(route('admin.whatsapp.operations-center.bulk.preview')),method:'POST',data:{_token:csrf,conversation_ids:ids,action},success:function(r){
   pending={conversation_ids:ids,action,preview_token:r.preview_token};
   $('#previewBody').html('<p><strong>'+escape(r.total_selected)+' selected · '+escape(r.eligible_count)+' eligible · '+escape(r.excluded_count)+' excluded</strong></p>'+r.items.map(x=>'<div class="ops-result"><strong>Conversation #'+escape(x.id)+' — '+escape(x.status.replaceAll('_',' '))+'</strong><p class="mb-0">'+escape(x.message||x.reason||x.error)+'</p></div>').join('')+'<p class="text-muted mt-3 mb-0">Eligibility is checked again when you confirm. This preview expires in five minutes.</p>');
   $('#confirmRecovery').prop('disabled',r.eligible_count===0);
  },error:function(xhr){$('#previewBody').text(xhr.responseJSON?.message||'Preview failed. No recovery was executed.');}});
 }
 $('#previewSelected').on('click',()=>preview($('.row-checkbox:checked').map(function(){return this.value;}).get(),$('#bulkAction').val()));
 $('.preview-one').on('click',function(){preview([$(this).data('id')],$(this).data('action'));});
 $('#confirmRecovery').on('click',function(){if(!pending)return;$(this).prop('disabled',true);const data=pending;pending=null;
 $.ajax({url:@json(route('admin.whatsapp.operations-center.bulk.execute')),method:'POST',data:{_token:csrf,...data},success:function(r){$('#previewBody').html('<p><strong>'+escape(r.success_count)+' completed · '+escape(r.failed_count)+' failed · '+escape(r.excluded_count)+' excluded</strong></p>'+r.items.map(x=>'<div class="ops-result">#'+escape(x.id)+': '+escape(x.status)+'<br>'+escape(x.error||x.message||x.reason)+'</div>').join(''));},error:function(xhr){$('#previewBody').text(xhr.responseJSON?.message||'Could not confirm the result. Check recovery history before trying again.');}});
 });
 $('#recoveryPreview').on('hidden.bs.modal',function(){pending=null;});
});
</script>
@endpush
