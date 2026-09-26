<?php

namespace Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\WhatsAppVendorConcierge\app\Models\ConciergeRecoveryAudit;
use Modules\WhatsAppVendorConcierge\app\Models\ProductListingDraft as Draft;
use Modules\WhatsAppVendorConcierge\app\Services\CoreAdapters\ProductMedia;
use Modules\WhatsAppVendorConcierge\app\Services\MediaPolicyService;
use Modules\WhatsAppVendorConcierge\app\Services\ProductListingFlow;
use Modules\WhatsAppVendorConcierge\app\Services\ProductVisionService;
use Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway;

class ProductDraftController extends Controller
{
    public function index(Request $r)
    {
        $drafts = Draft::with('store.module')->when($r->filled('status'), fn ($q) => $q->where('status', $r->string('status')->toString()))->latest('updated_at')->paginate(20);
        $missing = Category::withoutGlobalScopes()->where(fn ($q) => $q->whereNull('image')->orWhereIn('image', ['', 'def.png']))->orderBy('module_id')->paginate(30, ['*'], 'categories');

        return view('whatsappvendorconcierge::admin.product-drafts.index', compact('drafts', 'missing'));
    }

    public function show(Draft $draft)
    {
        $draft->load('store.module', 'conversation');
        $messages = collect(['inbound', 'outbound'])->map(fn ($direction) => $draft->conversation->messages()->where('direction', $direction)->latest('id')->first())->filter();

        return view('whatsappvendorconcierge::admin.product-drafts.show', compact('draft', 'messages'));
    }

    public function image(Draft $draft)
    {
        $id = $draft->data['media_id'] ?? 0;
        $m = app(ProductMedia::class)->owned($id, (int) $draft->store->vendor_id);
        $check = app(MediaPolicyService::class)->validateProductImage($m);
        abort_unless($check['valid'], 404);

        return response(Storage::disk($m->storage_disk)->get($m->file_path))->header('Content-Type', $check['mime_type'])->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function action(Request $r, Draft $draft, ProductListingFlow $flow)
    {
        $r->validate(['action' => 'required|in:resume,without_ai,retry_vision,resend,cancel', 'confirmed' => 'accepted']);

        return Cache::lock('product-draft-'.$draft->conversation_id, 90)->block(5, function () use ($r, $draft, $flow) {
            $draft->refresh();
            abort_unless(in_array($draft->status, ['active', 'saved'], true), 409);
            $action = $r->string('action')->toString();
            $c = $draft->conversation;
            $contact = $c->contact;
            abort_unless($c->state === 'ai_active' || $action === 'cancel', 409, 'Resolve explicit human ownership in the inbox first.');
            if ($action === 'cancel') {
                $draft->status = 'cancelled';
            } elseif ($action === 'retry_vision') {
                $m = app(ProductMedia::class)->owned((int) ($draft->data['media_id'] ?? 0), (int) $draft->store->vendor_id);
                $draft->vision = app(ProductVisionService::class)->analyse($m, $draft->store);
            } else {
                $inbound = $c->messages()->where('direction', 'inbound')->latest('created_at')->first();
                abort_unless($inbound && $inbound->created_at->gt(now()->subHours(24)) && ! $contact->is_blocked, 422, 'No open WhatsApp reply window.');
                if ($action !== 'resend') {
                    $draft->status = 'active';
                    $draft->stalled_turns = 0;
                    $draft->needs_attention = false;
                }
                if ($action === 'without_ai') {
                    $draft->vision = ['status' => 'disabled_by_admin'];
                }
                $response = app(WhatsAppGateway::class)->sendTextMessage($contact->phone_number, $flow->prompt($draft));
                abort_unless(! empty($response['messages'][0]['id']), 502, 'Meta did not accept the prompt.');
            }
            $draft->save();
            ConciergeRecoveryAudit::create(['conversation_id' => $c->id, 'contact_id' => $contact->id, 'admin_id' => auth('admin')->id(), 'action' => 'product_draft_'.$action, 'initiated_by' => 'admin', 'status' => 'success', 'is_dry_run' => false, 'reason' => 'Confirmed admin draft action', 'details' => ['draft_id' => $draft->id], 'correlation_id' => 'DRAFT-'.Str::uuid()]);

            return back()->with('saved',true);
        });
    }
}
