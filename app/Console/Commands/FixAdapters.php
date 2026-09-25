<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderDefinition;

class FixAdapters extends Command
{
    protected $signature = 'fix:adapters';
    protected $description = 'Fix adapter classes for AI provider definitions';

    public function handle()
    {
        $map = [
            'gemini' => \Modules\WhatsAppVendorConcierge\app\Services\AiAdapters\GeminiAdapter::class,
            'deepseek' => \Modules\WhatsAppVendorConcierge\app\Services\AiAdapters\DeepSeekAdapter::class,
            'groq' => \Modules\WhatsAppVendorConcierge\app\Services\AiAdapters\GroqAdapter::class,
            'nvidia_nim' => \Modules\WhatsAppVendorConcierge\app\Services\AiAdapters\NvidiaNimAdapter::class,
            'openrouter' => \Modules\WhatsAppVendorConcierge\app\Services\AiAdapters\OpenRouterAdapter::class,
        ];

        foreach ($map as $slug => $class) {
            $count = AiProviderDefinition::where('slug', $slug)->update(['adapter_class' => $class]);
            $this->info("Updated {$count} records for {$slug}");
        }
        
        $this->info('Done.');
    }
}
