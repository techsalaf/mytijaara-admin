from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/tests/Unit/AdditionalAiProviderSetupTest.php')
s=p.read_text();idx=s.rfind('\n}');s=s[:idx]+r'''
    public function test_registered_probe_uses_adapter_and_marks_only_text_inference_verified(): void
    {
        Http::fake(['https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'probe', 'model' => 'example:free',
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'OK'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1],
        ])]);
        $definition = AiProviderDefinition::where('slug', 'openrouter_free')->firstOrFail();
        $connection = AiProviderConnection::create(['definition_id' => $definition->id, 'name' => 'Probe', 'credentials' => ['api_key' => 'test'], 'is_active' => true, 'status' => 'models_discovered']);
        $connection->models()->create(['model_id' => 'example:free', 'name' => 'Example', 'is_enabled' => true, 'is_free_tier' => true, 'cost_per_million_input' => 0, 'cost_per_million_output' => 0]);
        $this->artisan('ai:test-models', ['connection_id' => $connection->id])->assertSuccessful();
        $this->assertSame('inference_verified', $connection->fresh()->status);
        Http::assertSentCount(1);
    }
'''+s[idx:];p.write_text(s)
p=Path('Modules/WhatsAppVendorConcierge/tests/Unit/AdditionalAiProvidersTest.php');s=p.read_text();idx=s.rfind('\n}');s=s[:idx]+r'''
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
'''+s[idx:];p.write_text(s)
