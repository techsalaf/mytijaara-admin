<?php
namespace Modules\WhatsAppVendorConcierge\app\Console\Commands;
use Illuminate\Console\Command;
use App\Models\Store;
use Modules\WhatsAppVendorConcierge\app\Services\VendorAccessNoticeService;
class VendorAccessNoticeCommand extends Command
{
    protected $signature='whatsapp:vendor-access-notice {--store=*} {--force}';
    protected $description='Preview or send audited launch access notices to approved active stores';
    public function handle(VendorAccessNoticeService $service): int
    {
        $query=Store::where('status',1)->where('active',1)->whereHas('vendor',fn($q)=>$q->where('status',1));
        if ($this->option('store')) $query->whereIn('id',$this->option('store'));
        $failed=false;
        foreach($query->get() as $store)foreach(['email','whatsapp'] as $channel){$r=$service->send($store,$channel,!$this->option('force'));$this->line(json_encode($r));$failed=$failed||in_array($r['status'],['failed','uncertain']);}
        return $failed?1:0;
    }
}
