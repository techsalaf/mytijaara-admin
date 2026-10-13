<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;

class FixOverrides extends Command
{
    protected $signature = 'fix:overrides';
    protected $description = 'Clear base url overrides if they match open ai standard for non-openai adapters';

    public function handle()
    {
        $connections = AiProviderConnection::all();
        foreach ($connections as $c) {
            $def = $c->definition;
            if ($def && $def->slug !== 'openai') {
                if ($c->base_url_override === 'https://api.openai.com/v1') {
                    $c->update(['base_url_override' => null]);
                    $this->info("Cleared override for {$c->name}");
                }
            }
        }
        $this->info('Done.');
    }
}
