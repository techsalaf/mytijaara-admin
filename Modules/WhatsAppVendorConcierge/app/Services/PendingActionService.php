<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use App\Models\Category;
use App\Models\Item;
use App\Models\Order;
use App\Models\Store;
use Modules\WhatsAppVendorConcierge\app\Services\CoreAdapters\OrderMutationService;
use Modules\WhatsAppVendorConcierge\app\Services\CoreAdapters\ProductMutationService;
use Modules\WhatsAppVendorConcierge\app\Services\CoreAdapters\StoreAvailabilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Modules\WhatsAppVendorConcierge\app\Jobs\SendWhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Models\PendingAction;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;

class PendingActionService
{
    public function __construct(
        protected StoreAvailabilityService $availabilityService,
        protected ProductMutationService $productMutationService,
        protected OrderMutationService $orderMutationService,
    ) {}

    public function prepareAvailability(int $contactId, int $conversationId, int $storeId, bool $active): PendingAction
    {
        $contact = WhatsAppContact::findOrFail($contactId);
        $conversation = WhatsAppConversation::findOrFail($conversationId);
        $this->authorize($contact, $conversation);
        $store = Store::whereKey($storeId)->where('vendor_id', $contact->vendor_id)->firstOrFail();
        $payload = ['store_id' => $store->id, 'active' => $active, 'previous_active' => (bool) $store->active];
        $preview = ($active ? 'Open' : 'Pause') . ' your store: ' . $store->name . '?';

        return $this->createAndDispatchAction($contact, $conversation, 'store_availability', $payload, $preview);
    }

    public function prepareProductPrice(int $contactId, int $conversationId, int $itemId, float $newPrice): PendingAction
    {
        $contact = WhatsAppContact::findOrFail($contactId);
        $conversation = WhatsAppConversation::findOrFail($conversationId);
        $this->authorize($contact, $conversation);

        $item = Item::whereKey($itemId)->firstOrFail();
        $store = Store::whereKey($item->store_id)->where('vendor_id', $contact->vendor_id)->firstOrFail();

        $payload = [
            'item_id' => $item->id,
            'store_id' => $store->id,
            'new_price' => $newPrice,
            'previous_price' => (float) $item->price,
        ];
        $preview = "Update price for *{$item->name}* from ₦" . number_format($item->price, 2) . " to *₦" . number_format($newPrice, 2) . "*?";

        return $this->createAndDispatchAction($contact, $conversation, 'product_price_update', $payload, $preview);
    }

    public function prepareProductStock(int $contactId, int $conversationId, int $itemId, int $newStock, ?array $variationStocks = null): PendingAction
    {
        $contact = WhatsAppContact::findOrFail($contactId);
        $conversation = WhatsAppConversation::findOrFail($conversationId);
        $this->authorize($contact, $conversation);

        $item = Item::whereKey($itemId)->firstOrFail();
        $store = Store::whereKey($item->store_id)->where('vendor_id', $contact->vendor_id)->firstOrFail();

        $payload = [
            'item_id' => $item->id,
            'store_id' => $store->id,
            'new_stock' => $newStock,
            'previous_stock' => (int) $item->stock,
            'variation_stocks' => $variationStocks,
            'previous_variations_hash' => hash('sha256', $item->variations ?: '[]'),
        ];
        $preview = "Update stock for *{$item->name}* from {$item->stock} to *{$newStock}* units?";
        foreach ($variationStocks ?? [] as $type => $stock) $preview .= "\n• {$type}: {$stock}";

        return $this->createAndDispatchAction($contact, $conversation, 'product_stock_update', $payload, $preview);
    }

    public function prepareProductAvailability(int $contactId, int $conversationId, int $itemId, bool $active): PendingAction
    {
        $contact = WhatsAppContact::findOrFail($contactId);
        $conversation = WhatsAppConversation::findOrFail($conversationId);
        $this->authorize($contact, $conversation);

        $item = Item::whereKey($itemId)->firstOrFail();
        $store = Store::whereKey($item->store_id)->where('vendor_id', $contact->vendor_id)->firstOrFail();

        $payload = [
            'item_id' => $item->id,
            'store_id' => $store->id,
            'active' => $active,
            'previous_status' => (int) $item->status,
        ];
        $preview = ($active ? 'Activate' : 'Deactivate') . " product *{$item->name}* in your catalog?";

        return $this->createAndDispatchAction($contact, $conversation, 'product_availability_toggle', $payload, $preview);
    }

    public function prepareProductCreate(int $contactId, int $conversationId, int $storeId, array $productData): PendingAction
    {
        $contact = WhatsAppContact::findOrFail($contactId);
        $conversation = WhatsAppConversation::findOrFail($conversationId);
        $this->authorize($contact, $conversation);

        $store = Store::whereKey($storeId)->where('vendor_id', $contact->vendor_id)->firstOrFail();
        $category = Category::findOrFail($productData['category_id']);
        $this->productMutationService->validateCreationRequirements($store, $productData);

        $payload = [
            'store_id' => $store->id,
            'data' => $productData,
        ];
        $priceFormatted = number_format($productData['price'], 2);
        $preview = "Add *{$productData['name']}* (₦{$priceFormatted}, Category: {$category->name}, Stock: {$productData['stock']}) to your catalog?";
        $preview .= "\nDescription: ".\Illuminate\Support\Str::limit($productData['description'], 200);
        if (!empty($productData['store_category_id'])) {
            $storeCategory = \App\Models\StoreCategory::where('store_id', $store->id)->findOrFail($productData['store_category_id']);
            $preview .= "\nStore category: ".\Illuminate\Support\Str::limit($storeCategory->name, 80);
        }

        return $this->createAndDispatchAction($contact, $conversation, 'product_create', $payload, $preview);
    }

    public function prepareOrderStatus(int $contactId, int $conversationId, int $orderId, string $targetStatus, ?string $reason = null, ?string $otp = null): PendingAction
    {
        $contact = WhatsAppContact::findOrFail($contactId);
        $conversation = WhatsAppConversation::findOrFail($conversationId);
        $this->authorize($contact, $conversation);

        $order = Order::whereKey($orderId)->firstOrFail();
        $store = Store::whereKey($order->store_id)->where('vendor_id', $contact->vendor_id)->firstOrFail();

        $payload = [
            'order_id' => $order->id,
            'store_id' => $store->id,
            'target_status' => $targetStatus,
            'previous_status' => $order->order_status,
            'reason' => $reason,
            'otp' => $otp,
        ];

        $statusLabels = [
            'confirmed' => 'Accept & Confirm',
            'processing' => 'Start Preparation for',
            'handover' => 'Mark Ready for Pickup',
            'delivered' => 'Mark Delivered',
            'canceled' => 'Reject / Cancel',
        ];
        $actionName = $statusLabels[$targetStatus] ?? ucfirst($targetStatus);
        $preview = "{$actionName} Order #{$order->id} (₦" . number_format($order->order_amount, 2) . ")?";

        return $this->createAndDispatchAction($contact, $conversation, 'order_status_update', $payload, $preview);
    }

    public function confirm(string $token, int $contactId, int $conversationId, bool $cancel = false): string
    {
        return DB::transaction(function () use ($token, $contactId, $conversationId, $cancel) {
            $contact = WhatsAppContact::lockForUpdate()->findOrFail($contactId);
            $conversation = WhatsAppConversation::lockForUpdate()->findOrFail($conversationId);
            $this->authorize($contact, $conversation);

            $action = PendingAction::where('action_token', $token)
                ->where('contact_id', $contactId)
                ->where('vendor_id', $contact->vendor_id)
                ->where('conversation_id', $conversationId)
                ->lockForUpdate()
                ->first();

            if (!$action || $action->status !== 'pending') {
                return 'This action is unavailable or already completed.';
            }

            if ($action->expires_at->isPast()) {
                $action->update(['status' => 'expired']);
                return 'This confirmation expired. Please request the action again.';
            }

            if ($cancel) {
                $action->update(['status' => 'cancelled', 'cancelled_at' => now()]);
                return 'Action cancelled.';
            }

            if (!hash_equals($action->payload_hash, hash('sha256', json_encode($action->payload, JSON_THROW_ON_ERROR)))) {
                $action->update(['status' => 'failed', 'result_metadata' => ['code' => 'payload_changed']]);
                return 'This action changed. Please request a new preview.';
            }

            $key = 'whatsapp:mutation:' . $contact->vendor_id;
            if (RateLimiter::tooManyAttempts($key, 10)) {
                return 'Please wait a minute before making more changes.';
            }
            RateLimiter::hit($key, 60);

            // Execute based on action_type
            return match ($action->action_type) {
                'store_availability' => $this->executeStoreAvailability($action, $contact),
                'product_price_update' => $this->executeProductPriceUpdate($action, $contact),
                'product_stock_update' => $this->executeProductStockUpdate($action, $contact),
                'product_availability_toggle' => $this->executeProductAvailabilityToggle($action, $contact),
                'product_create' => $this->executeProductCreate($action, $contact),
                'order_status_update' => $this->executeOrderStatusUpdate($action, $contact),
                default => 'This action is not available through WhatsApp yet.',
            };
        });
    }

    protected function executeStoreAvailability(PendingAction $action, WhatsAppContact $contact): string
    {
        $store = Store::whereKey($action->payload['store_id'])
            ->where('vendor_id', $contact->vendor_id)
            ->lockForUpdate()
            ->firstOrFail();

        if ((int) $store->status !== 1 || (bool) $store->active !== $action->payload['previous_active']) {
            $action->update(['status' => 'cancelled', 'cancelled_at' => now(), 'result_metadata' => ['code' => 'store_changed']]);
            return 'Your store status changed. Please request a new preview.';
        }

        $this->availabilityService->set($store, $contact->vendor_id, $action->payload['active']);
        $action->update([
            'status' => 'executed',
            'confirmed_at' => now(),
            'executed_at' => now(),
            'result_metadata' => ['store_id' => $store->id],
        ]);

        return $action->payload['active'] ? 'Your store is open, subject to its schedule.' : 'Your store is paused.';
    }

    protected function executeProductPriceUpdate(PendingAction $action, WhatsAppContact $contact): string
    {
        $item = Item::whereKey($action->payload['item_id'])->lockForUpdate()->firstOrFail();
        if ((float) $item->price !== (float) $action->payload['previous_price']) return $this->cancelChangedAction($action);
        $updated = $this->productMutationService->updatePrice($item, $contact->vendor_id, (float) $action->payload['new_price']);

        $action->update([
            'status' => 'executed',
            'confirmed_at' => now(),
            'executed_at' => now(),
            'result_metadata' => ['item_id' => $updated->id, 'new_price' => $updated->price],
        ]);

        return $updated->relationLoaded('conciergeReview')
            ? "The price change for *{$updated->name}* was submitted for admin approval."
            : "Price for *{$updated->name}* updated to ₦" . number_format($updated->price, 2) . ".";
    }

    protected function executeProductStockUpdate(PendingAction $action, WhatsAppContact $contact): string
    {
        $item = Item::whereKey($action->payload['item_id'])->lockForUpdate()->firstOrFail();
        if ((int) $item->stock !== (int) $action->payload['previous_stock']) return $this->cancelChangedAction($action);
        if (isset($action->payload['previous_variations_hash']) && !hash_equals($action->payload['previous_variations_hash'], hash('sha256', $item->variations ?: '[]'))) return $this->cancelChangedAction($action);
        $updated = $this->productMutationService->updateStock($item, $contact->vendor_id, (int) $action->payload['new_stock'], $action->payload['variation_stocks'] ?? null);

        $action->update([
            'status' => 'executed',
            'confirmed_at' => now(),
            'executed_at' => now(),
            'result_metadata' => ['item_id' => $updated->id, 'new_stock' => $updated->stock],
        ]);

        return "Stock for *{$updated->name}* updated to {$updated->stock} units.";
    }

    protected function executeProductAvailabilityToggle(PendingAction $action, WhatsAppContact $contact): string
    {
        $item = Item::whereKey($action->payload['item_id'])->lockForUpdate()->firstOrFail();
        if ((int) $item->status !== (int) $action->payload['previous_status']) return $this->cancelChangedAction($action);
        $updated = $this->productMutationService->toggleAvailability($item, $contact->vendor_id, (bool) $action->payload['active']);

        $action->update([
            'status' => 'executed',
            'confirmed_at' => now(),
            'executed_at' => now(),
            'result_metadata' => ['item_id' => $updated->id, 'status' => $updated->status],
        ]);

        $statusWord = $updated->status === 1 ? 'activated' : 'deactivated';
        return "Product *{$updated->name}* is now {$statusWord}.";
    }

    protected function executeProductCreate(PendingAction $action, WhatsAppContact $contact): string
    {
        $store = Store::whereKey($action->payload['store_id'])->where('vendor_id', $contact->vendor_id)->firstOrFail();
        $item = $this->productMutationService->createProduct($store, $contact->vendor_id, $action->payload['data']);

        $action->update([
            'status' => 'executed',
            'confirmed_at' => now(),
            'executed_at' => now(),
            'result_metadata' => ['item_id' => $item->id],
        ]);

        return $item->relationLoaded('conciergeReview')
            ? "Product *{$item->name}* was submitted for admin approval."
            : "✅ Product *{$item->name}* (₦" . number_format($item->price, 2) . ") was successfully added to your shop catalog.";
    }

    protected function executeOrderStatusUpdate(PendingAction $action, WhatsAppContact $contact): string
    {
        $order = Order::whereKey($action->payload['order_id'])->lockForUpdate()->firstOrFail();
        if ($order->order_status !== $action->payload['previous_status']) return $this->cancelChangedAction($action);
        $updated = $this->orderMutationService->transitionStatus(
            $order,
            $contact->vendor_id,
            $action->payload['target_status'],
            ['reason' => $action->payload['reason'] ?? null, 'otp' => $action->payload['otp'] ?? null]
        );

        $action->update([
            'status' => 'executed',
            'confirmed_at' => now(),
            'executed_at' => now(),
            'result_metadata' => ['order_id' => $updated->id, 'order_status' => $updated->order_status],
        ]);

        $statusMessages = [
            'confirmed' => "Order #{$updated->id} has been confirmed.",
            'processing' => "Order #{$updated->id} is now being prepared.",
            'handover' => "Order #{$updated->id} is marked ready for pickup.",
            'delivered' => "Order #{$updated->id} marked delivered.",
            'canceled' => "Order #{$updated->id} has been cancelled.",
        ];

        return $statusMessages[$updated->order_status] ?? "Order #{$updated->id} status updated to {$updated->order_status}.";
    }

    private function cancelChangedAction(PendingAction $action): string
    {
        $action->update(['status' => 'cancelled', 'cancelled_at' => now(), 'result_metadata' => ['code' => 'state_changed']]);
        return 'This item or order changed after your preview. Please request a new preview.';
    }

    private function createAndDispatchAction(
        WhatsAppContact $contact,
        WhatsAppConversation $conversation,
        string $actionType,
        array $payload,
        string $preview
    ): PendingAction {
        $action = PendingAction::create([
            'contact_id' => $contact->id,
            'vendor_id' => $contact->vendor_id,
            'conversation_id' => $conversation->id,
            'action_type' => $actionType,
            'status' => 'pending',
            'payload' => $payload,
            'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            'preview' => $preview,
            'action_token' => bin2hex(random_bytes(24)),
            'expires_at' => now()->addMinutes(10),
        ]);

        SendWhatsAppMessage::dispatch($contact->phone_number, 'button', [
            'body' => $action->preview,
            'buttons' => [
                ['id' => 'action_confirm_' . $action->action_token, 'title' => 'Confirm'],
                ['id' => 'action_cancel_' . $action->action_token, 'title' => 'Cancel'],
            ],
        ], $conversation->id)->onConnection(config('whatsapp-vendor-concierge.queue.connection'))
            ->onQueue(config('whatsapp-vendor-concierge.queue.jobs.send_message'))->afterCommit();

        return $action;
    }

    private function authorize(WhatsAppContact $contact, WhatsAppConversation $conversation): void
    {
        abort_unless(!$contact->is_blocked && $contact->isVendor() && (int) $contact->vendor?->status === 1
            && (int) $conversation->contact_id === (int) $contact->id
            && (int) $conversation->vendor_id === (int) $contact->vendor_id
            && $conversation->state === 'ai_active', 403);
    }
}
