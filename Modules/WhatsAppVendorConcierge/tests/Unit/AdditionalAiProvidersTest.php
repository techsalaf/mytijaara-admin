<?php
namespace Modules\WhatsAppVendorConcierge\Tests\Unit;

use Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\{Agent, HasTools, Tool};
use Laravel\Ai\Promptable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Modules\WhatsAppVendorConcierge\app\Models\{AiProviderConnection, AiProviderDefinition, AiProviderModel};
use Modules\WhatsAppVendorConcierge\app\Services\AiAdapters\{AdapterFactory, CloudflareWorkersAiAdapter, OpenRouterFreeAdapter};

class ProviderProbeTool implements Tool {
    public static int $calls = 0;
    public function description(): string { return 'Read a harmless fixture.'; }
    public function schema(JsonSchema $schema): array { return []; }
    public function handle(Request $request): string { self::$calls++; return 'fixture-ok'; }
}
class ProviderProbeAgent implements Agent, HasTools {
    use Promptable;
    public function instructions(): string { return 'Use the probe tool.'; }
    public function tools(): iterable { return [new ProviderProbeTool]; }
}

class AdditionalAiProvidersTest extends TestCase
{
    private function connection(string $slug): AiProviderConnection
    {
        $connection = new AiProviderConnection(['credentials' => [
            'api_key' => 'test-secret', 'account_id' => str_repeat('a', 32), 'free_plan_confirmed' => true,
        ]]);
        $connection->setRelation('definition', new AiProviderDefinition(['slug' => $slug, 'default_base_url' => $slug === 'nvidia_nim' ? 'https://integrate.api.nvidia.com/v1' : null]));
        return $connection;
    }

    public function test_all_three_transports_execute_tool_round_trips_on_the_correct_host(): void
    {
        foreach (['nvidia_nim' => 'meta/llama-3.1-8b-instruct', 'cerebras' => 'gpt-oss-120b', 'cloudflare_workers_ai' => CloudflareWorkersAiAdapter::MODEL, 'openrouter_free' => 'example/tool:free'] as $slug => $id) {
            $connection = $this->connection($slug);
            $url = $connection->getBaseUrl().'/chat/completions';
            ProviderProbeTool::$calls = 0;
            Http::preventStrayRequests();
            Http::fake([$url => Http::sequence()->push([
                'id' => 'probe-1', 'model' => $id,
                'choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                    ['id' => 'call-1', 'type' => 'function', 'function' => ['name' => 'ProviderProbeTool', 'arguments' => '{}']],
                ]], 'finish_reason' => 'tool_calls']],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 3],
            ])->push([
                'id' => 'probe-2', 'model' => $id,
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Ready'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 2],
            ])]);
            $model = new AiProviderModel(['model_id' => $id, 'is_free_tier' => $slug !== 'cerebras', 'cost_per_million_input' => 0, 'cost_per_million_output' => 0]);
            $result = AdapterFactory::forConnection($connection)->invokeAgent($connection, $model, new ProviderProbeAgent, 'Check');
            $this->assertSame('Ready', (string) $result['response']);
            $this->assertSame(1, ProviderProbeTool::$calls, $slug);
            $this->assertGreaterThan(0, $result['prompt_tokens']);
            Http::assertSent(fn ($request) => $request->url() === $url && $request->hasHeader('Authorization', 'Bearer test-secret') && collect($request['messages'])->contains(fn ($m) => ($m['role'] ?? '') === 'tool'));
        }
    }

    public function test_free_discovery_rejects_paid_missing_prices_and_guessed_tools(): void
    {
        Http::fake([
            'https://openrouter.ai/api/v1/key' => Http::response(['data' => ['label' => 'test']]),
            'https://openrouter.ai/api/v1/models' => Http::response(['data' => [
                ['id' => 'a/good:free', 'pricing' => ['prompt' => '0', 'completion' => '0'], 'supported_parameters' => ['tools']],
                ['id' => 'a/paid', 'pricing' => ['prompt' => '0', 'completion' => '0'], 'supported_parameters' => ['tools']],
                ['id' => 'a/missing:free', 'supported_parameters' => ['tools']],
                ['id' => 'a/gpt-4:free', 'pricing' => ['prompt' => '0', 'completion' => '0']],
                ['id' => 'a/paid:free', 'pricing' => ['prompt' => '0.001', 'completion' => '0'], 'supported_parameters' => ['tools']],
            ]]),
        ]);
        $models = (new OpenRouterFreeAdapter)->discoverModels($this->connection('openrouter_free'));
        $this->assertSame(['a/good:free'], array_column($models, 'id'));
    }

    public function test_invalid_openrouter_key_cannot_pass_using_public_models(): void
    {
        Http::fake(['*/key' => Http::response([], 401), '*/models' => Http::response(['data' => []])]);
        $result = (new OpenRouterFreeAdapter)->testConnection($this->connection('openrouter_free'));
        $this->assertFalse($result['success']);
        Http::assertSentCount(1);
    }

    public function test_paid_model_is_blocked_even_if_manually_enabled(): void
    {
        Http::fake();
        try {
            (new OpenRouterFreeAdapter)->invokeAgent($this->connection('openrouter_free'), new AiProviderModel(['model_id' => 'paid/model', 'is_free_tier' => true]), new ProviderProbeAgent, 'Check');
            $this->fail('Paid model should be blocked.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('only permits', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_cloudflare_discovery_and_account_validation(): void
    {
        Http::fake(['*/ai/models/search*' => Http::response(['success' => true, 'result' => [
            ['name' => CloudflareWorkersAiAdapter::MODEL], ['name' => '@cf/unknown'],
        ]])]);
        $connection = $this->connection('cloudflare_workers_ai');
        $models = (new CloudflareWorkersAiAdapter)->discoverModels($connection);
        $this->assertSame([CloudflareWorkersAiAdapter::MODEL], array_column($models, 'id'));
        $connection->credentials = ['api_key' => 'test-secret', 'account_id' => '../../bad'];
        $this->expectException(\InvalidArgumentException::class);
        $connection->getBaseUrl();
    }

    public function test_cerebras_trial_is_not_classified_as_free(): void
    {
        Http::fake([
            'https://api.cerebras.ai/v1/models' => Http::response(['data' => [['id' => 'gpt-oss-120b']]]),
            'https://api.cerebras.ai/public/v1/models*' => Http::response(['data' => [[
                'id' => 'gpt-oss-120b', 'pricing' => ['prompt' => '0.00000035', 'completion' => '0.00000075'],
            ]]]),
        ]);
        $adapter = AdapterFactory::forConnection($connection = $this->connection('cerebras'));
        $models = $adapter->discoverModels($connection);
        $this->assertFalse($models[0]['is_free_tier']);
        $this->assertEquals(0.35, $models[0]['cost_per_million_input']);
    }
    public function test_cloudflare_does_not_invoke_without_free_plan_attestation(): void
    {
        Http::fake();
        $connection = $this->connection('cloudflare_workers_ai');
        $connection->credentials = ['api_key' => 'test', 'account_id' => str_repeat('a', 32)];
        try {
            (new CloudflareWorkersAiAdapter)->invokeAgent($connection, new AiProviderModel(['model_id' => CloudflareWorkersAiAdapter::MODEL]), new ProviderProbeAgent, 'Check');
            $this->fail('Missing plan confirmation should block invocation.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Workers Free', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_new_chat_adapter_reports_rate_limits_to_existing_fallback_policy(): void
    {
        Http::fake(['https://api.cerebras.ai/v1/chat/completions' => Http::response(['error' => ['message' => 'Rate limit exceeded']], 429)]);
        $connection = $this->connection('cerebras');
        $adapter = AdapterFactory::forConnection($connection);
        try {
            $adapter->invokeAgent($connection, new AiProviderModel(['model_id' => 'gpt-oss-120b']), new ProviderProbeAgent, 'Check');
            $this->fail('Quota failure should propagate to the router.');
        } catch (\Throwable $e) {
            $this->assertSame('rate_limit', $adapter->normaliseError($e)['type']);
        }
    }

}
