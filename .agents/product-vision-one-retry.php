<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Modules\WhatsAppVendorConcierge\app\Models\ProductListingDraft;
use Modules\WhatsAppVendorConcierge\app\Services\{ProductVisionService,CoreAdapters\ProductMedia};
Illuminate\Support\Facades\Cache::lock('product-draft-5',90)->block(5,function(){
$d=ProductListingDraft::findOrFail(1);if($d->status!=='active'||($d->data['media_id']??null)!==29||($d->vision['error']??'')!=='provider_http_503'){echo 'No eligible retry'.PHP_EOL;return;}
$result=app(ProductVisionService::class)->analyse(app(ProductMedia::class)->owned(29,2),$d->store);$d->update(['vision'=>$result]);echo json_encode($result).PHP_EOL;
});
