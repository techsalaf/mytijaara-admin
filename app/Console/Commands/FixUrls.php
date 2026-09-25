<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderDefinition;

class FixUrls extends Command
{
    protected $signature = 'fix:urls';
    protected $description = 'Fix default base urls for AI provider definitions';

    public function handle()
    {
        $map = [
            'gemini' => 'https://generativelanguage.googleapis.com/v1beta',
            'deepseek' => 'https://api.deepseek.com',
            'groq' => 'https://api.groq.com/openai/v1',
            'nvidia_nim' => 'https://integrate.api.nvidia.com/v1',
            'openrouter' => 'https://openrouter.ai/api/v1',
        ];

        foreach ($map as $slug => $url) {
            $count = AiProviderDefinition::where('slug', $slug)->update(['default_base_url' => $url]);
            $this->info("Updated {$count} records for {$slug}");
        }
        
        $this->info('Done.');
    }
}
