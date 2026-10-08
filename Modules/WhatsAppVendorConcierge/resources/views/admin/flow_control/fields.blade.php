@foreach($nodes as $node)
 @php($type=$node['type']??'')
 @php($required=($node['required']??false) || ($node['min-uploaded-photos']??0)>0)
 @if($type==='If')
  <div class="fc-field-note">{{ __('Conditional branch') }}: {{ $node['condition'] }}</div>
  @include('whatsappvendorconcierge::admin.flow_control.fields',['nodes'=>$node['then']??[]])
  @unless($preview??false)@include('whatsappvendorconcierge::admin.flow_control.fields',['nodes'=>$node['else']??[]])@endunless
 @elseif($preview??false)
  @if(in_array($type,['TextBody','TextCaption'],true))
   <p class="text-muted">{{ str_contains($node['text']??'','${') ? __('Server supplies the current summary and exact policy versions here.') : ($node['text']??'') }}</p>
  @elseif($type==='Footer')
   <button type="button" disabled class="btn btn-success w-100 mt-3">{{ $node['label'] }}</button>
  @elseif($type==='OptIn')
   <label class="d-block my-3"><input type="checkbox" disabled> {{ $node['label'] }} <span class="text-danger">*</span></label>
  @elseif($type==='EmbeddedLink')
   <span class="text-primary d-block my-2">{{ $node['text'] }}</span>
  @else
   <label class="d-block mt-3">{{ $node['label']??$node['name'] }} @if($required)<span class="text-danger">*</span>@endif</label>
   @if(in_array($type,['PhotoPicker','DocumentPicker'],true))
    <div class="fc-preview-field text-center"><span aria-hidden="true">＋</span><p class="mb-0">{{ __('Camera / gallery upload') }}</p><small>{{ $node['max-file-size-kb']??0 }} KiB · {{ $node['max-uploaded-photos']??1 }} {{ __('file maximum') }}</small></div>
   @elseif(in_array($type,['Dropdown','CheckboxGroup'],true))
    <select class="form-control" disabled aria-label="{{ $node['label'] }}"><option>{{ __('Eligible options supplied by the server') }}</option></select>
   @else
    <input class="form-control" disabled placeholder="{{ $node['helper-text']??__('Vendor enters this value') }}" aria-label="{{ $node['label']??$node['name'] }}">
   @endif
  @endif
 @else
  <div class="fc-preview-field">
   <strong>{{ $node['label']??$node['text']??$node['name']??$type }}</strong>
   <div class="fc-field-note">{{ $type }} · {{ $required?__('Required field'):__('Optional / display') }} · {{ $node['name']??'—' }}</div>
   @if(isset($node['visible']))<div class="fc-field-note">{{ __('Visible when') }}: {{ $node['visible'] }}</div>@endif
   <div class="fc-field-note">{{ __('Data source') }}: {{ is_string($node['data-source']??null)?$node['data-source']:__('Server-owned or vendor entry') }}</div>
   <div class="fc-field-note">{{ __('Canonical validation rules') }}: {{ $node['input-type']??$node['helper-text']??__('Server checks allowed values, domains and bounds') }}</div>
   @if(in_array($type,['PhotoPicker','DocumentPicker'],true))<div class="fc-field-note">{{ __('Private staged media; content MIME and dimensions validated before canonical promotion') }} · {{ $node['max-file-size-kb']??0 }} KiB · {{ $screen['id']==='PLAN_LOGO'?'logo':($screen['id']==='COVER'?'cover':'optional KYC/TIN image') }}</div>@endif
   @if(in_array($node['name']??'', $screen['sensitive']??[],true))<div class="fc-field-note">{{ __('Sensitive field; encrypted draft, excluded from analytics and AI context') }}</div>@endif
   @if(isset($node['name']))<div class="fc-field-note">{{ __('Submit mapping') }}: {{ $node['name']==='media' ? __('Screen-bound secure media service → canonical registration media') : $node['name'].' → FlowFieldMapper → VendorSelfRegistrationService' }}</div>@endif
  </div>
 @endif
@endforeach
