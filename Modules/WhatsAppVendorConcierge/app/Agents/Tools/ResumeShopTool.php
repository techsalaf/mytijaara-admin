<?php

namespace Modules\WhatsAppVendorConcierge\app\Agents\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class ResumeShopTool extends BaseVendorTool
{
    public function description(): string
    {
        return 'Resume/open the vendor\'s shop (set active to true). Customers can place orders again.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
        ];
    }

    public function handle(Request $request): string
    {
        return $this->prepareAvailability(true);
    }
}
