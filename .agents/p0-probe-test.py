from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/tests/Unit/AdditionalAiProviderSetupTest.php');s=p.read_text();idx=s.rfind('\n}');s=s[:idx]+r'''
    public function test_tool_probe_cannot_pass_when_provider_only_returns_text(): void
    {
        Http::fake(['https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'id'=>'probe', 'model'=>'example:free',
            'choices'=>[['message'=>['role'=>'assistant','content'=>'OK'],'finish_reason'=>'stop']],
        ])]);
        $definition=AiProviderDefinition::where('slug','openrouter_free')->firstOrFail();
        $connection=AiProviderConnection::create(['definition_id'=>$definition->id,'name'=>'Tool probe','credentials'=>['api_key'=>'test'],'is_active'=>true,'status'=>'models_discovered']);
        $connection->models()->create(['model_id'=>'example:free','name'=>'Example','is_enabled'=>true,'is_free_tier'=>true,'cost_per_million_input'=>0,'cost_per_million_output'=>0]);
        $this->artisan('ai:test-models',['connection_id'=>$connection->id,'--tools'=>true])->assertFailed();
        $this->assertFalse($connection->fresh()->verification['models']['example:free']['tools']['passed']);
        $this->assertSame('models_discovered',$connection->fresh()->status);
    }
'''+s[idx:];p.write_text(s)
