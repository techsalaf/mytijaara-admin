<?php

namespace Modules\WhatsAppVendorConcierge\database\seeders;

use Illuminate\Database\Seeder;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppAiProvider;

class WhatsAppAiProvidersTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $providers = [
            [
                'name' => 'OpenAI GPT-4o',
                'driver' => 'openai',
                'base_url' => null,
                'api_key' => null,
                'model' => 'gpt-4o',
                'is_active' => false,
                'priority' => 0,
            ],
            [
                'name' => 'OpenAI GPT-4o Mini',
                'driver' => 'openai',
                'base_url' => null,
                'api_key' => null,
                'model' => 'gpt-4o-mini',
                'is_active' => false,
                'priority' => 1,
            ],
            [
                'name' => 'Anthropic Claude 3.5 Sonnet',
                'driver' => 'anthropic',
                'base_url' => null,
                'api_key' => null,
                'model' => 'claude-3-5-sonnet-20240620',
                'is_active' => false,
                'priority' => 2,
            ],
            [
                'name' => 'Anthropic Claude 3 Haiku',
                'driver' => 'anthropic',
                'base_url' => null,
                'api_key' => null,
                'model' => 'claude-3-haiku-20240307',
                'is_active' => false,
                'priority' => 3,
            ],
            [
                'name' => 'DeepSeek Chat',
                'driver' => 'openai',
                'base_url' => 'https://api.deepseek.com/v1',
                'api_key' => null,
                'model' => 'deepseek-chat',
                'is_active' => false,
                'priority' => 4,
            ],
            [
                'name' => 'DeepSeek Coder',
                'driver' => 'openai',
                'base_url' => 'https://api.deepseek.com/v1',
                'api_key' => null,
                'model' => 'deepseek-coder',
                'is_active' => false,
                'priority' => 5,
            ],
            [
                'name' => 'Google Gemini 1.5 Pro',
                'driver' => 'openai',
                'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
                'api_key' => null,
                'model' => 'gemini-1.5-pro',
                'is_active' => false,
                'priority' => 6,
            ],
            [
                'name' => 'Google Gemini 1.5 Flash',
                'driver' => 'openai',
                'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
                'api_key' => null,
                'model' => 'gemini-1.5-flash',
                'is_active' => false,
                'priority' => 7,
            ],
            [
                'name' => 'Groq Llama 3 8B',
                'driver' => 'openai',
                'base_url' => 'https://api.groq.com/openai/v1',
                'api_key' => null,
                'model' => 'llama3-8b-8192',
                'is_active' => false,
                'priority' => 8,
            ],
            [
                'name' => 'Groq Mixtral',
                'driver' => 'openai',
                'base_url' => 'https://api.groq.com/openai/v1',
                'api_key' => null,
                'model' => 'mixtral-8x7b-32768',
                'is_active' => false,
                'priority' => 9,
            ],
        ];

        foreach ($providers as $provider) {
            WhatsAppAiProvider::firstOrCreate(
                ['name' => $provider['name']],
                $provider
            );
        }
    }
}
