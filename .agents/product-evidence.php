<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$m=Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMedia::find(29);echo json_encode(['media'=>$m->only(['id','whatsapp_media_id','status','mime_type','file_size','file_path','downloaded_at']),'validation'=>app(Modules\WhatsAppVendorConcierge\app\Services\MediaPolicyService::class)->validateProductImage($m)]).PHP_EOL;
foreach(['modules','categories','units','attributes','add_ons','cancellation_reasons','refund_reasons','zones','tags','jobs','failed_jobs','items'] as $t){if(!Illuminate\Support\Facades\Schema::hasTable($t))continue;echo $t.' '.json_encode(in_array($t,['modules','categories','units','attributes'])?DB::table($t)->get():['count'=>DB::table($t)->count()]).PHP_EOL;}
echo 'category_schema '.json_encode(DB::select('SHOW COLUMNS FROM categories')).PHP_EOL;
