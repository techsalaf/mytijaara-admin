<?php
namespace Modules\WhatsAppVendorConcierge\tests\Hardening;
use Illuminate\Support\Facades\Http;
use Modules\WhatsAppVendorConcierge\app\Services\ProductVisionService;
use Modules\WhatsAppVendorConcierge\app\Models\{AiProviderDefinition,AiProviderConnection,AiProviderModel,WhatsAppMedia};
class ProductVisionFailureTest extends OperationsFixtureTestCase {
 private function model(string $name=ProductVisionService::MODEL): void {
  $definition=AiProviderDefinition::create(['slug'=>'nvidia_nim','name'=>'NVIDIA','adapter_class'=>'NvidiaNimAdapter','default_base_url'=>ProductVisionService::ENDPOINT]);
  $connection=AiProviderConnection::create(['definition_id'=>$definition->id,'name'=>'Vision','credentials'=>['api_key'=>'fixture-key'],'is_active'=>true]);
  AiProviderModel::create(['connection_id'=>$connection->id,'model_id'=>$name,'name'=>'Fixture','is_enabled'=>true,'supports_vision'=>true]);
 }
 private function analyse(): array {return app(ProductVisionService::class)->analyse(WhatsAppMedia::find((int)$this->productData([])['image']),$this->store);}
 public function test_unverified_model_flag_cannot_enable_vision(): void {$this->model('unverified-text-model');Http::fake();$this->assertSame('unavailable',$this->analyse()['status']);Http::assertNothingSent();}
 public function test_timeout_falls_back_without_changing_a_product(): void {$this->model();Http::fake(fn()=>throw new \Illuminate\Http\Client\ConnectionException('Fixture timeout'));$this->assertSame('timeout_or_transport',$this->analyse()['error']);$this->assertSame(0,\App\Models\Item::count());}
 public function test_invalid_json_schema_and_http_failure_are_explicit(): void {
  $this->model();Http::fake(['*/chat/completions'=>Http::sequence()->push(['choices'=>[['message'=>['content'=>'not json']]]])->push(['choices'=>[['message'=>['content'=>'{"name":"Bag"}']]]])->push([],503)]);
  $this->assertSame('invalid_result',$this->analyse()['error']);$this->assertSame('invalid_result',$this->analyse()['error']);$this->assertSame('provider_http_503',$this->analyse()['error']);
 }
 public function test_low_confidence_requires_vendor_confirmation_and_discards_commercial_guesses(): void {
  $this->model();Http::fake(['*/chat/completions'=>Http::response(['choices'=>[['message'=>['content'=>json_encode(['name'=>'Bag','description'=>'A blue bag','category_id'=>$this->category->id,'confidence'=>0.2,'price'=>999,'stock'=>100])]]]])]);
  $r=$this->analyse();$this->assertSame('success',$r['status']);$this->assertTrue($r['requires_confirmation']);$this->assertSame(0.2,$r['confidence']);$this->assertArrayNotHasKey('price',$r['suggestions']);$this->assertArrayNotHasKey('stock',$r['suggestions']);
 }
}
