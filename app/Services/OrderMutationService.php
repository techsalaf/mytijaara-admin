<?php

namespace App\Services;

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
     * Transition an order to a target status with full core validation and side effects.
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

                if ((int) $locked->is_guest === 0 && class_exists(OrderLogic::class) && method_exists(OrderLogic::class, 'refund_before_delivered')) {
                    OrderLogic::refund_before_delivered($locked);
                }

                // Restore stock if module tracks stock
                $moduleType = $locked->module?->module_type;
                if ($moduleType && config("module.{$moduleType}.stock") && class_exists(ProductLogic::class) && method_exists(ProductLogic::class, 'update_stock')) {
                    foreach ($locked->details as $detail) {
                        $item = $detail->campaign ?? $detail->item;
                        if ($item) {
                            $variant = json_decode($detail->variation, true);
                            $variantType = !empty($variant) ? $variant[0]['type'] : null;
                            ProductLogic::update_stock($item, -$detail->quantity, $variantType)?->save();
                        }
                    }
                }
            }

            // Handle DELIVERED side-effects
            if ($targetStatus === 'delivered') {
                $locked->payment_status = 'paid';
                $locked->delivered = now();

                if ($locked->transaction === null && class_exists(OrderLogic::class) && method_exists(OrderLogic::class, 'create_transaction')) {
                    $unpaidPayment = OrderPayment::where('payment_status', 'unpaid')->where('order_id', $locked->id)->first()?->payment_method;
                    $method = ($locked->payment_method === 'cash_on_delivery' || $unpaidPayment === 'cash_on_delivery') ? 'store' : 'admin';
                    OrderLogic::create_transaction($locked, $method, null);
                }

                $store->increment('order_count');
                if ((int) $locked->is_guest === 0 && $locked->customer) {
                    $locked->customer->increment('order_count');
                }
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

            // Notify customer / platform
            if (class_exists(Helpers::class) && method_exists(Helpers::class, 'send_order_notification')) {
                @Helpers::send_order_notification($locked);
            }

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
