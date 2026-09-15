<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\CoreAdapters;

use App\CentralLogics\Helpers;
use App\CentralLogics\OrderLogic;
use App\CentralLogics\ProductLogic;
use App\Models\BusinessSetting;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderMutationService
{
    /**
     * Maintained port of Vendor/OrderController::status, with API pickup/OTP guards.
     *
     * @throws ValidationException
     */
    public function transitionStatus(Order $order, int $vendorId, string $targetStatus, array $extra = []): Order
    {
        $this->authorizeOrderOwnership($order, $vendorId);

        $allowedStatuses = ['confirmed', 'processing', 'handover', 'delivered', 'canceled'];
        if (!in_array($targetStatus, $allowedStatuses, true)) {
            throw ValidationException::withMessages([
                'order_status' => ["Invalid status '{$targetStatus}'. Allowed: " . implode(', ', $allowedStatuses)],
            ]);
        }

        return DB::transaction(function () use ($order, $vendorId, $targetStatus, $extra) {
            $locked = Order::whereKey($order->id)
                ->where('store_id', $order->store_id)
                ->lockForUpdate()
                ->firstOrFail();

            $store = $locked->store ?? Store::findOrFail($locked->store_id);
            $this->authorizeOrderOwnership($locked, $vendorId);
            if ($locked->order_status === $targetStatus) {
                return $locked; // Never repeat financial or inventory side effects.
            }
            if ($locked->picked_up !== null || in_array($locked->order_status, ['canceled', 'failed', 'refunded'])) {
                throw ValidationException::withMessages(['order_status' => ['This order can no longer be changed by the store.']]);
            }
            if ($locked->order_type === 'pos') {
                throw ValidationException::withMessages(['order_status' => ['POS orders use the POS workflow.']]);
            }
            if ($targetStatus === 'canceled' && !config('canceled_by_store')) {
                throw ValidationException::withMessages(['order_status' => ['Store cancellation is disabled.']]);
            }
            if ($targetStatus === 'confirmed' && !$store->sub_self_delivery && config('order_confirmation_model') === 'deliveryman' && $locked->order_type !== 'take_away') {
                throw ValidationException::withMessages(['order_status' => ['This order must be confirmed by the delivery agent.']]);
            }
            if ($targetStatus === 'delivered' && config('order_delivery_verification') &&
                (!isset($extra['otp']) || !hash_equals((string) $locked->otp, (string) $extra['otp']))) {
                throw ValidationException::withMessages(['otp' => ['A matching delivery verification code is required.']]);
            }

            // Invariant 1: Cannot change status after delivered
            if ($locked->delivered !== null || $locked->order_status === 'delivered') {
                throw ValidationException::withMessages([
                    'order_status' => ['Cannot change order status after delivery.'],
                ]);
            }

            // Invariant 2: Cannot cancel after confirmed
            if ($targetStatus === 'canceled' && ($locked->confirmed !== null || $locked->order_status !== 'pending')) {
                throw ValidationException::withMessages([
                    'order_status' => ['Cannot cancel an order after it has been confirmed.'],
                ]);
            }

            // Invariant 3: Cancellation requires reason
            if ($targetStatus === 'canceled' && empty($extra['reason'])) {
                throw ValidationException::withMessages([
                    'reason' => ['A cancellation reason is required when rejecting or cancelling an order.'],
                ]);
            }

            // Invariant 4: Delivery transitions
            if ($targetStatus === 'delivered' && $locked->order_type !== 'take_away' && !$store->sub_self_delivery) {
                throw ValidationException::withMessages([
                    'order_status' => ['Only take-away orders or self-delivery orders can be marked delivered directly by the store.'],
                ]);
            }

            // Invariant 5: Processing prerequisite
            if ($targetStatus === 'processing' && $locked->order_status !== 'confirmed' && $locked->order_status !== 'accepted') {
                throw ValidationException::withMessages([
                    'order_status' => ['Order must be confirmed before preparation can begin.'],
                ]);
            }

            // Invariant 6: Handover prerequisite
            if ($targetStatus === 'handover' && $locked->order_status !== 'processing') {
                throw ValidationException::withMessages([
                    'order_status' => ['Order must be in processing before it can be marked ready for pickup/delivery.'],
                ]);
            }

            // Handle CANCELED side-effects
            if ($targetStatus === 'canceled') {
                $locked->cancellation_reason = $extra['reason'] ?? 'Cancelled by vendor';
                $locked->canceled_by = 'store';
                $locked->canceled = now();

                Helpers::increment_order_count($store);
                if ((int) $locked->is_guest === 0) {
                    OrderLogic::refund_before_delivered($locked);
                }

                // Restore stock if module tracks stock
                $moduleType = $locked->module?->module_type;
                $hasStock = $moduleType && config("module.{$moduleType}.stock");
                $hasFlashDiscount = $locked->flash_admin_discount_amount > 0 && $locked->flash_store_discount_amount > 0;
                if ($hasStock || $hasFlashDiscount) {
                    foreach ($locked->details as $detail) {
                        $item = $detail->campaign ?? $detail->item;
                        if ($hasStock && $item) {
                            $variant = json_decode($detail->variation, true);
                            $variantType = !empty($variant) ? $variant[0]['type'] : null;
                            ProductLogic::update_stock($item, -$detail->quantity, $variantType)?->save();
                        }
                        if ($hasFlashDiscount && $detail->item) {
                            ProductLogic::update_flash_stock($detail->item, $detail->quantity, true)?->save();
                        }
                    }
                }
            }

            // Handle DELIVERED side-effects
            if ($targetStatus === 'delivered') {
                if ($locked->transaction === null) {
                    $unpaidPayment = OrderPayment::where('payment_status', 'unpaid')->where('order_id', $locked->id)->first()?->payment_method;
                    $method = ($locked->payment_method === 'cash_on_delivery' || $unpaidPayment === 'cash_on_delivery') ? 'store' : 'admin';
                    if (!OrderLogic::create_transaction($locked, $method, null)) {
                        throw new \RuntimeException('Order accounting transaction could not be created.');
                    }
                    if ($locked->delivery_man_id) {
                        Helpers::deliverymanLoyaltyPointHistory(deliveryManId: $locked->delivery_man_id, amount: $locked->order_amount, transactionType: 'earn_on_order_completion', pointConversionType: 'credit', reference: $locked->id);
                    }
                }

                OrderLogic::update_unpaid_order_payment(order_id: $locked->id, payment_method: $locked->payment_method);
                $locked->payment_status = 'paid';
                $locked->delivered = now();
                foreach ($locked->details as $detail) {
                    $detail->item?->increment('order_count');
                }
                $locked->delivery_man?->increment('order_count');

                $store->increment('order_count');
                if ((int) $locked->is_guest === 0 && $locked->customer) {
                    $locked->customer->increment('order_count');
                }
            }

            if (in_array($targetStatus, ['canceled', 'delivered']) && $locked->delivery_man) {
                $rider = $locked->delivery_man;
                $rider->current_orders = max(0, $rider->current_orders - 1);
                $rider->save();
            }

            // Handle CONFIRMED
            if ($targetStatus === 'confirmed') {
                $locked->confirmed = now();
            }

            // Handle PROCESSING
            if ($targetStatus === 'processing') {
                $locked->processing = now();
                $locked->processing_time = $extra['processing_time'] ?? (int) explode('-', (string) $store->delivery_time)[0] ?? 30;
            }

            // Handle HANDOVER
            if ($targetStatus === 'handover') {
                $locked->handover = now();
            }

            $locked->order_status = $targetStatus;
            $locked->save();

            // Do not announce a state before the enclosing confirmation commits.
            DB::afterCommit(function () use ($locked): void {
                try { Helpers::send_order_notification($locked); }
                catch (\Throwable $error) {
                    \Illuminate\Support\Facades\Log::error('Order notification failed after commit', ['order_id' => $locked->id, 'exception' => $error::class]);
                }
            });

            return $locked->fresh(['details', 'store', 'customer']);
        });
    }

    private function authorizeOrderOwnership(Order $order, int $vendorId): void
    {
        $store = $order->store ?? Store::find($order->store_id);
        if (!$store || (int) $store->vendor_id !== $vendorId) {
            abort(403, 'Unauthorized order access.');
        }
    }
}
