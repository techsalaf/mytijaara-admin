<?php

namespace Modules\WhatsAppVendorConcierge\app\Agents\Tools;

use Modules\WhatsAppVendorConcierge\app\Agents\VendorAiContext;
use App\Models\Vendor;
use App\Models\Store;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Base class for vendor tools providing common functionality
 */
abstract class BaseVendorTool implements Tool
{
    public function __construct(
        protected readonly VendorAiContext $context,
        protected readonly ?Vendor $vendor = null,
        protected readonly ?Store $store = null,
    ) {}

    protected function ensureVendor(): ?Vendor
    {
        return $this->vendor;
    }

    protected function ensureStore(): ?Store
    {
        return $this->store;
    }

    protected function requireVendor(): Vendor
    {
        if (!$this->vendor) {
            throw new \RuntimeException('Vendor not available for this tool');
        }
        return $this->vendor;
    }

    protected function requireStore(): Store
    {
        if (!$this->store) {
            throw new \RuntimeException('Store not available for this tool');
        }
        return $this->store;
    }

    protected function formatCurrency(float|int $amount): string
    {
        return '₦' . number_format($amount, 2);
    }

    protected function formatNaira(float|int $amount): string
    {
        return '₦' . number_format($amount, 0);
    }

    protected function recordTool(string $name): void
    {
        $this->context->recordTool($name);
    }

    protected function prepareAvailability(bool $active): string
    {
        if (!$this->context->contactId || !$this->context->conversationId) {
            return 'Please request this action in your active WhatsApp conversation.';
        }
        try {
            app(\Modules\WhatsAppVendorConcierge\app\Services\PendingActionService::class)->prepareAvailability(
                $this->context->contactId, $this->context->conversationId, $this->requireStore()->id, $active
            );
            return 'A preview with Confirm and Cancel buttons has been sent. The change has not been applied.';
        } catch (\Throwable $e) {
            return 'This store change is unavailable. Please check your dashboard or contact support.';
        }
    }

    protected function prepareProductPrice(int $itemId, float $newPrice): string
    {
        if (!$this->context->contactId || !$this->context->conversationId) {
            return 'Please request this action in your active WhatsApp conversation.';
        }
        try {
            $action = app(\Modules\WhatsAppVendorConcierge\app\Services\PendingActionService::class)->prepareProductPrice(
                $this->context->contactId, $this->context->conversationId, $itemId, $newPrice
            );
            return "A confirmation preview for *{$action->preview}* has been sent. Tap Confirm to apply the new price.";
        } catch (\Throwable $e) {
            return 'Unable to prepare price update: ' . $e->getMessage();
        }
    }

    protected function prepareProductStock(int $itemId, int $newStock): string
    {
        if (!$this->context->contactId || !$this->context->conversationId) {
            return 'Please request this action in your active WhatsApp conversation.';
        }
        try {
            $action = app(\Modules\WhatsAppVendorConcierge\app\Services\PendingActionService::class)->prepareProductStock(
                $this->context->contactId, $this->context->conversationId, $itemId, $newStock
            );
            return "A confirmation preview for *{$action->preview}* has been sent. Tap Confirm to apply the stock change.";
        } catch (\Throwable $e) {
            return 'Unable to prepare stock update: ' . $e->getMessage();
        }
    }

    protected function prepareProductAvailability(int $itemId, bool $active): string
    {
        if (!$this->context->contactId || !$this->context->conversationId) {
            return 'Please request this action in your active WhatsApp conversation.';
        }
        try {
            $action = app(\Modules\WhatsAppVendorConcierge\app\Services\PendingActionService::class)->prepareProductAvailability(
                $this->context->contactId, $this->context->conversationId, $itemId, $active
            );
            return "A confirmation preview for *{$action->preview}* has been sent. Tap Confirm to apply.";
        } catch (\Throwable $e) {
            return 'Unable to prepare product availability update: ' . $e->getMessage();
        }
    }

    protected function prepareProductCreate(array $data): string
    {
        if (!$this->context->contactId || !$this->context->conversationId) {
            return 'Please request this action in your active WhatsApp conversation.';
        }
        try {
            $action = app(\Modules\WhatsAppVendorConcierge\app\Services\PendingActionService::class)->prepareProductCreate(
                $this->context->contactId, $this->context->conversationId, $this->requireStore()->id, $data
            );
            return "A confirmation preview for *{$action->preview}* has been sent. Tap Confirm to add the product to your shop.";
        } catch (\Throwable $e) {
            return 'Unable to prepare product creation: ' . $e->getMessage();
        }
    }

    protected function prepareOrderStatus(int $orderId, string $status, ?string $reason = null): string
    {
        if (!$this->context->contactId || !$this->context->conversationId) {
            return 'Please request this action in your active WhatsApp conversation.';
        }
        try {
            $action = app(\Modules\WhatsAppVendorConcierge\app\Services\PendingActionService::class)->prepareOrderStatus(
                $this->context->contactId, $this->context->conversationId, $orderId, $status, $reason
            );
            return "A confirmation preview for *{$action->preview}* has been sent. Tap Confirm to update order status.";
        } catch (\Throwable $e) {
            return 'Unable to prepare order update: ' . $e->getMessage();
        }
    }
}
