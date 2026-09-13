<?php

namespace Modules\WhatsAppVendorConcierge\app\Agents\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class PauseShopTool extends BaseVendorTool
{
    public function description(): string
    {
        return 'Pause/close the vendor\'s shop (set active to false). Customers will not be able to place new orders. Requires confirmation.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
        ];
    }

    public function handle(Request $request): string
    {
        return $this->prepareAvailability(false);
    }
}
