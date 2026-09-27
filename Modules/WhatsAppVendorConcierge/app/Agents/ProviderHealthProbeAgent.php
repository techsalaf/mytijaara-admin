<?php
namespace Modules\WhatsAppVendorConcierge\app\Agents;
use Laravel\Ai\Contracts\{Agent, HasTools};
use Laravel\Ai\Promptable;
use Modules\WhatsAppVendorConcierge\app\Agents\Tools\ProviderHealthProbeTool;
class ProviderHealthProbeAgent implements Agent, HasTools {
    use Promptable;
    public ProviderHealthProbeTool $probe;
    public function __construct(private bool $withTools = false) { $this->probe = new ProviderHealthProbeTool; }
    public function instructions(): string { return $this->withTools ? 'Call ProviderHealthProbeTool once, then reply OK. This is a read-only health check.' : 'Reply OK only.'; }
    public function tools(): iterable { return $this->withTools ? [$this->probe] : []; }
}
