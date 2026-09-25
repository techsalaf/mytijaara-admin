<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;

class FixOverrides2 extends Command
{
    protected $signature = 'fix:overrides2';
    protected $description = 'Clear base url overrides for non-openai adapters';

    public function handle()
    {
        $connections = AiProviderConnection::all();
        foreach ($connections as $c) {
            $def = $c->definition;
            if ($def && $def->slug === 'gemini') {
                $c->update(['base_url_override' => null]);
                $this->info("Cleared override for {$c->name}");
            }
        }
        $this->info('Done.');
    }
}
