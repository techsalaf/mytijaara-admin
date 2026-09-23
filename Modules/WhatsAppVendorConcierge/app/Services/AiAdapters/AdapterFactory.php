<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\AiAdapters;

use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderDefinition;
use Modules\WhatsAppVendorConcierge\app\Services\AiAdapters\Contracts\ProviderAdapterInterface;

class AdapterFactory
{
    /**
     * Resolve the provider adapter for a connection.
     */
    public static function forConnection(AiProviderConnection $connection): ProviderAdapterInterface
    {
        $definition = $connection->definition;
        return self::forDefinition($definition);
    }

    /**
     * Resolve the provider adapter for a definition.
     */
    public static function forDefinition(?AiProviderDefinition $definition): ProviderAdapterInterface
    {
        if ($definition && !empty($definition->adapter_class) && class_exists($definition->adapter_class)) {
            return app($definition->adapter_class);
        }

        $slug = $definition?->slug ?? 'openai';

        return match ($slug) {
            'gemini' => app(GeminiAdapter::class),
            'groq' => app(GroqAdapter::class),
            'openrouter' => app(OpenRouterAdapter::class),
            'nvidia_nim' => app(NvidiaNimAdapter::class),
            default => app(OpenAiCompatibleAdapter::class),
        };
    }
}
