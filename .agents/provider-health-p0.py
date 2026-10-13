from pathlib import Path
r=Path('Modules/WhatsAppVendorConcierge')
p=r/'tests/Unit/AdditionalAiProvidersTest.php';s=p.read_text().replace("new AiProviderDefinition(['slug' => $slug])", "new AiProviderDefinition(['slug' => $slug, 'default_base_url' => $slug === 'nvidia_nim' ? 'https://integrate.api.nvidia.com/v1' : null])").replace("['cerebras' => 'gpt-oss-120b', 'cloudflare_workers_ai'", "['nvidia_nim' => 'meta/llama-3.1-8b-instruct', 'cerebras' => 'gpt-oss-120b', 'cloudflare_workers_ai'");p.write_text(s)
p=r/'database/migrations/2026_09_27_140000_create_inbound_processing_receipts.php';s=p.read_text().replace("        Schema::create('whatsapp_inbound_receipts'", "        Schema::table('ai_provider_connections', fn (Blueprint $table) => $table->json('verification')->nullable());\n        Schema::create('whatsapp_inbound_receipts'").replace("public function down(): void { Schema::dropIfExists('whatsapp_inbound_receipts'); }", "public function down(): void { Schema::dropIfExists('whatsapp_inbound_receipts'); Schema::table('ai_provider_connections', fn (Blueprint $table) => $table->dropColumn('verification')); }");p.write_text(s)
p=r/'app/Models/AiProviderConnection.php';s=p.read_text().replace("    protected $fillable = [", "    protected $fillable = [\n        'verification',").replace("    protected $casts = [", "    protected $casts = [\n        'verification' => 'array',");p.write_text(s)
p=r/'app/Http/Controllers/Admin/AiProviderController.php';s=p.read_text().replace("            $updates['status'] = 'unverified';", "            $updates['verification'] = [];\n            $updates['status'] = 'unverified';");needle="        $result = $adapter->testConnection($aiProvider);";s=s.replace(needle,needle+"\n        $aiProvider->update(['verification' => array_merge($aiProvider->verification ?? [], ['endpoint_check' => ['passed' => (bool) $result['success'], 'at' => now()->toIso8601String()]])]);");p.write_text(s)
p=r/'app/Services/ModelDiscoveryService.php';s=p.read_text().replace("            'status' => $apiDiscovered", "            'verification' => array_merge($connection->verification ?? [], ['discovery' => ['passed' => $apiDiscovered, 'count' => $syncedCount, 'at' => now()->toIso8601String()]]),\n            'status' => $apiDiscovered");p.write_text(s)
(r/'app/Agents/ProviderHealthProbeAgent.php').write_text(r'''<?php
namespace Modules\WhatsAppVendorConcierge\app\Agents;
use Laravel\Ai\Contracts\{Agent, HasTools};
use Laravel\Ai\Promptable;
use Modules\WhatsAppVendorConcierge\app\Agents\Tools\ProviderHealthProbeTool;
class ProviderHealthProbeAgent implements Agent, HasTools {
    use Promptable;
    public ProviderHealthProbeTool $probe;
    public function __construct(private bool $withTools = false) { $this->probe = new ProviderHealthProbeTool; }
    public function instructions(): string { return $this->withTools ? 'Call ProviderHealthProbeTool once, then reply OK. This is a read-only health check.' : 'Reply OK only.'; }
    public function tools(): iterable { return $this->withTools ? [$this->probe] : []; }
}
''',encoding='utf8')
(r/'app/Agents/Tools/ProviderHealthProbeTool.php').write_text(r'''<?php
namespace Modules\WhatsAppVendorConcierge\app\Agents\Tools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Illuminate\Contracts\JsonSchema\JsonSchema;
class ProviderHealthProbeTool implements Tool {
    public int $calls = 0;
    public function description(): string { return 'Return a harmless health-check value. No account data is accessed or changed.'; }
    public function schema(JsonSchema $schema): array { return []; }
    public function handle(Request $request): string { $this->calls++; return 'health-ok'; }
}
''',encoding='utf8')
p=r/'app/Console/Commands/AiTestModelsCommand.php';s=p.read_text().replace("{connection_id : Connection to test}'", "{connection_id : Connection to test} {--tools : Verify a harmless real tool call as well as text}'");a=s.index('        $agent = new class');b=s.index('        $adapter =',a);s=s[:a]+s[b:];s=s.replace('            try {', "            $agent = new \\Modules\\WhatsAppVendorConcierge\\app\\Agents\\ProviderHealthProbeAgent((bool) $this->option('tools'));\n            $kind = $this->option('tools') ? 'tools' : 'text';\n            $passed = false;\n            try {",1).replace("                $this->info($model->model_id.': text inference passed (tool execution not tested).');", "                if ($this->option('tools') && $agent->probe->calls < 1) throw new \\RuntimeException('Tool was not executed.');\n                $passed = true;\n                $this->info($model->model_id.': '.$kind.' probe passed.');")
s=s.replace("            }\n        }\n        if (!$failed)", "            }\n            $verification = $connection->verification ?? [];\n            $verification['models'][$model->model_id][$kind] = ['passed' => $passed, 'at' => now()->toIso8601String()];\n            $connection->update(['verification' => $verification]);\n        }\n        if (!$failed)").replace("['status' => 'inference_verified',", "['status' => $this->option('tools') ? 'tool_calling_verified' : 'inference_verified',")
p.write_text(s)
