<?php

namespace Modules\WhatsAppVendorConcierge\database\seeders;

use Illuminate\Database\Seeder;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderDefinition;

class AiProviderDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            [
                'slug' => 'openai',
                'name' => 'OpenAI',
                'adapter_class' => 'Modules\\WhatsAppVendorConcierge\\app\\Services\\AiAdapters\\OpenAiCompatibleAdapter',
                'default_base_url' => 'https://api.openai.com/v1',
                'auth_type' => 'api_key',
                'supports_model_discovery' => true,
                'supports_tool_calling' => true,
                'supports_vision' => true,
                'supports_streaming' => true,
                'documentation_url' => 'https://platform.openai.com/docs',
                'catalogue_models' => [
                    ['id' => 'gpt-4o', 'name' => 'GPT-4o', 'tools' => true, 'vision' => true, 'free' => false, 'context' => 128000],
                    ['id' => 'gpt-4o-mini', 'name' => 'GPT-4o Mini', 'tools' => true, 'vision' => true, 'free' => false, 'context' => 128000],
                    ['id' => 'o3-mini', 'name' => 'o3 Mini', 'tools' => true, 'vision' => false, 'free' => false, 'context' => 200000],
                ],
            ],
            [
                'slug' => 'gemini',
                'name' => 'Google Gemini',
                'adapter_class' => 'Modules\\WhatsAppVendorConcierge\\app\\Services\\AiAdapters\\GeminiAdapter',
                'default_base_url' => 'https://generativelanguage.googleapis.com/v1beta',
                'auth_type' => 'api_key',
                'supports_model_discovery' => true,
                'supports_tool_calling' => true,
                'supports_vision' => true,
                'supports_streaming' => true,
                'documentation_url' => 'https://ai.google.dev/docs',
                'catalogue_models' => [
                    ['id' => 'gemini-2.0-flash', 'name' => 'Gemini 2.0 Flash', 'tools' => true, 'vision' => true, 'free' => true, 'context' => 1048576],
                    ['id' => 'gemini-1.5-flash', 'name' => 'Gemini 1.5 Flash', 'tools' => true, 'vision' => true, 'free' => true, 'context' => 1048576],
                    ['id' => 'gemini-1.5-pro', 'name' => 'Gemini 1.5 Pro', 'tools' => true, 'vision' => true, 'free' => true, 'context' => 2097152],
                ],
            ],
            [
                'slug' => 'groq',
                'name' => 'Groq Cloud',
                'adapter_class' => 'Modules\\WhatsAppVendorConcierge\\app\\Services\\AiAdapters\\GroqAdapter',
                'default_base_url' => 'https://api.groq.com/openai/v1',
                'auth_type' => 'api_key',
                'supports_model_discovery' => true,
                'supports_tool_calling' => true,
                'supports_vision' => false,
                'supports_streaming' => true,
                'documentation_url' => 'https://console.groq.com/docs',
                'catalogue_models' => [
                    ['id' => 'llama-3.3-70b-versatile', 'name' => 'Llama 3.3 70B Versatile', 'tools' => true, 'vision' => false, 'free' => true, 'context' => 128000],
                    ['id' => 'llama-3.1-8b-instant', 'name' => 'Llama 3.1 8B Instant', 'tools' => true, 'vision' => false, 'free' => true, 'context' => 128000],
                    ['id' => 'mixtral-8x7b-32768', 'name' => 'Mixtral 8x7B', 'tools' => false, 'vision' => false, 'free' => true, 'context' => 32768],
                ],
            ],
            [
                'slug' => 'openrouter',
                'name' => 'OpenRouter',
                'adapter_class' => 'Modules\\WhatsAppVendorConcierge\\app\\Services\\AiAdapters\\OpenRouterAdapter',
                'default_base_url' => 'https://openrouter.ai/api/v1',
                'auth_type' => 'api_key',
                'supports_model_discovery' => true,
                'supports_tool_calling' => true,
                'supports_vision' => true,
                'supports_streaming' => true,
                'documentation_url' => 'https://openrouter.ai/docs',
                'catalogue_models' => [
                    ['id' => 'meta-llama/llama-3.3-70b-instruct:free', 'name' => 'Llama 3.3 70B (Free)', 'tools' => true, 'vision' => false, 'free' => true, 'context' => 131072],
                    ['id' => 'google/gemini-2.0-flash-exp:free', 'name' => 'Gemini 2.0 Flash (Free)', 'tools' => true, 'vision' => true, 'free' => true, 'context' => 1048576],
                    ['id' => 'deepseek/deepseek-chat:free', 'name' => 'DeepSeek V3 (Free)', 'tools' => true, 'vision' => false, 'free' => true, 'context' => 64000],
                ],
            ],
            [
                'slug' => 'nvidia_nim',
                'name' => 'NVIDIA NIM',
                'adapter_class' => 'Modules\\WhatsAppVendorConcierge\\app\\Services\\AiAdapters\\NvidiaNimAdapter',
                'default_base_url' => 'https://integrate.api.nvidia.com/v1',
                'auth_type' => 'api_key',
                'supports_model_discovery' => true,
                'supports_tool_calling' => true,
                'supports_vision' => false,
                'supports_streaming' => true,
                'documentation_url' => 'https://build.nvidia.com',
                'catalogue_models' => [
                    ['id' => 'meta/llama-3.3-70b-instruct', 'name' => 'Meta Llama 3.3 70B Instruct', 'tools' => true, 'vision' => false, 'free' => true, 'context' => 128000],
                    ['id' => 'mistralai/mistral-large-2-instruct', 'name' => 'Mistral Large 2', 'tools' => true, 'vision' => false, 'free' => true, 'context' => 128000],
                ],
            ],
            [
                'slug' => 'anthropic',
                'name' => 'Anthropic Claude',
                'adapter_class' => 'Modules\\WhatsAppVendorConcierge\\app\\Services\\AiAdapters\\OpenAiCompatibleAdapter',
                'default_base_url' => 'https://api.anthropic.com/v1',
                'auth_type' => 'api_key',
                'supports_model_discovery' => false,
                'supports_tool_calling' => true,
                'supports_vision' => true,
                'supports_streaming' => true,
                'documentation_url' => 'https://docs.anthropic.com',
                'catalogue_models' => [
                    ['id' => 'claude-3-5-sonnet-20241022', 'name' => 'Claude 3.5 Sonnet', 'tools' => true, 'vision' => true, 'free' => false, 'context' => 200000],
                    ['id' => 'claude-3-5-haiku-20241022', 'name' => 'Claude 3.5 Haiku', 'tools' => true, 'vision' => true, 'free' => false, 'context' => 200000],
                ],
            ],
            [
                'slug' => 'deepseek',
                'name' => 'DeepSeek',
                'adapter_class' => 'Modules\\WhatsAppVendorConcierge\\app\\Services\\AiAdapters\\OpenAiCompatibleAdapter',
                'default_base_url' => 'https://api.deepseek.com/v1',
                'auth_type' => 'api_key',
                'supports_model_discovery' => true,
                'supports_tool_calling' => true,
                'supports_vision' => false,
                'supports_streaming' => true,
                'documentation_url' => 'https://platform.deepseek.com',
                'catalogue_models' => [
                    ['id' => 'deepseek-chat', 'name' => 'DeepSeek V3 Chat', 'tools' => true, 'vision' => false, 'free' => false, 'context' => 64000],
                    ['id' => 'deepseek-reasoner', 'name' => 'DeepSeek R1 Reasoner', 'tools' => false, 'vision' => false, 'free' => false, 'context' => 64000],
                ],
            ],
        ];

        foreach ($definitions as $def) {
            AiProviderDefinition::updateOrCreate(
                ['slug' => $def['slug']],
                $def
            );
        }
    }
}
