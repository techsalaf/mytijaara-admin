<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Modules\WhatsAppVendorConcierge\app\Models\{WhatsAppConversation,WhatsAppContact};
use Modules\WhatsAppVendorConcierge\app\Services\{ProductListingFlow,ProductVisionService,CoreAdapters\ProductMedia};
$c=WhatsAppConversation::findOrFail(5);$contact=WhatsAppContact::findOrFail(4);
if((int)$c->contact_id!==4 || (int)$contact->vendor_id!==2 || App\Models\Store::findOrFail(2)->name!=='Tijaara Mart')throw new RuntimeException('Unexpected owner');
$d=app(ProductListingFlow::class)->start($c,$contact);
Illuminate\Support\Facades\Cache::lock('product-draft-5',90)->block(5,function()use($d){$d->refresh();if(!$d->vision && !empty($d->data['media_id'])){$m=app(ProductMedia::class)->owned((int)$d->data['media_id'],2);$d->update(['vision'=>app(ProductVisionService::class)->analyse($m,$d->store)]);}});
$d->refresh();echo json_encode(['draft_id'=>$d->id,'status'=>$d->status,'step'=>$d->step,'name'=>$d->data['name']??null,'price'=>$d->data['price']??null,'stock'=>$d->data['stock']??null,'vision'=>$d->vision,'product_id'=>$d->item_id,'missing_category_images'=>Illuminate\Support\Facades\DB::table('categories')->whereIn('image',['','def.png'])->count()]).PHP_EOL;
