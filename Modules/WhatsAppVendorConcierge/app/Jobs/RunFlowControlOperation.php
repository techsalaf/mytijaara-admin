<?php

namespace Modules\WhatsAppVendorConcierge\app\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\WhatsAppVendorConcierge\app\Services\FlowControl\Lifecycle;

class RunFlowControlOperation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 80;

    public function __construct(public string $operationId)
    {
        $this->onConnection(config('whatsapp-vendor-concierge.queue.connection', 'database'));
        $this->onQueue(config('whatsapp-vendor-concierge.queue.name', 'default'));
        $this->afterCommit();
    }

    public function handle(Lifecycle $service): void
    {
        $service->run($this->operationId);
    }
}
