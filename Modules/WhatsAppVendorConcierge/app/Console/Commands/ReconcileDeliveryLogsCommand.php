<?php
namespace Modules\WhatsAppVendorConcierge\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\WhatsAppVendorConcierge\app\Services\Operations\DeliveryLogReconciler;

class ReconcileDeliveryLogsCommand extends Command
{
    protected $signature = 'whatsapp:reconcile-delivery-logs {--force : Import missing acceptance records after reviewing a dry run}';
    protected $description = 'Preview or reconcile missing inbox replies from local provider acceptance logs; never sends messages';
    public function handle(DeliveryLogReconciler $service): int
    {
        $this->line(json_encode($service->run(!$this->option('force')), JSON_PRETTY_PRINT));
        return self::SUCCESS;
    }
}
