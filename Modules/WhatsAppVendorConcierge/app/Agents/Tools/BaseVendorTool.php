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
}