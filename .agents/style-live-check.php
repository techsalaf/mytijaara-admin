<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Modules\WhatsAppVendorConcierge\app\Services\{WhatsAppCopy,ProductListingPresenter,VendorAccessNoticeService};
if(!class_exists(WhatsAppCopy::class)){echo "Awaiting release activation\n";exit;}
$d=Modules\WhatsAppVendorConcierge\app\Models\ProductListingDraft::findOrFail(1);
$review=app(ProductListingPresenter::class)->review($d);
echo json_encode(['login_url'=>app(VendorAccessNoticeService::class)->loginUrl(),'review_length'=>mb_strlen($review),'grouped_review'=>str_contains($review,'💰 *Price & stock*')&&str_contains($review,'🎨 *Variants*'),'bullets'=>substr_count($review,'•'),'native_bold'=>!str_contains($review,'**'),'formatted_ai_example'=>WhatsAppCopy::format('**Ready**'),'item_id_unchanged'=>$d->item_id]).PHP_EOL;
