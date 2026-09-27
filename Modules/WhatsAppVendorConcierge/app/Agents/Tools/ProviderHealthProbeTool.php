<?php
namespace Modules\WhatsAppVendorConcierge\app\Agents\Tools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Illuminate\Contracts\JsonSchema\JsonSchema;
class ProviderHealthProbeTool implements Tool {
    public int $calls = 0;
    public function description(): string { return 'Return a harmless health-check value. No account data is accessed or changed.'; }
    public function schema(JsonSchema $schema): array { return []; }
    public function handle(Request $request): string { $this->calls++; return 'health-ok'; }
}
