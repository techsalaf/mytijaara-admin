<?php

namespace Modules\WhatsAppVendorConcierge\app\Agents;

/**
 * Request-scoped context that tools write structured data into.
 * The service reads it after the agent prompt completes to include
 * structured data in responses and to log which tools ran.
 */
class VendorAiContext
{
    private array $vendorProfile     = [];
    private array $products          = [];
    private array $orders            = [];
    private array $salesData         = [];
    private array $toolsInvoked      = [];
    private array $responses         = [];

    public function setVendorProfile(array $profile): void
    {
        $this->vendorProfile = $profile;
    }

    public function addProducts(array $products): void
    {
        $this->products = array_merge($this->products, $products);
    }

    public function addOrders(array $orders): void
    {
        $this->orders = array_merge($this->orders, $orders);
    }

    public function addSalesData(array $sales): void
    {
        $this->salesData = array_merge($this->salesData, $sales);
    }

    public function recordTool(string $toolName): void
    {
        if (! in_array($toolName, $this->toolsInvoked, true)) {
            $this->toolsInvoked[] = $toolName;
        }
    }

    public function addResponse(string $key, mixed $data): void
    {
        $this->responses[$key] = $data;
    }

    public function getVendorProfile(): array
    {
        return $this->vendorProfile;
    }

    public function getProducts(): array
    {
        return $this->products;
    }

    public function getOrders(): array
    {
        return $this->orders;
    }

    public function getSalesData(): array
    {
        return $this->salesData;
    }

    public function getResponses(): array
    {
        return $this->responses;
    }

    /** Returns the unique names of every tool that was called during this turn. */
    public function getToolsInvoked(): array
    {
        return $this->toolsInvoked;
    }

    public function reset(): void
    {
        $this->vendorProfile  = [];
        $this->products       = [];
        $this->orders         = [];
        $this->salesData      = [];
        $this->toolsInvoked   = [];
        $this->responses      = [];
    }
}