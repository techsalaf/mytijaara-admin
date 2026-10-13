<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$live=false;
$stores=DB::table('stores')->whereIn('id',[1,2])->orderBy('id')->get();
if($stores->pluck('name')->all()!==['Emirate Luxury','Tijaara Mart'])throw new RuntimeException('Store identity changed');
if(DB::table('modules')->where('id',3)->value('module_type')!=='ecommerce')throw new RuntimeException('Target is not Shop');
$items=DB::table('items')->whereIn('store_id',[1,2])->get();
echo json_encode(['live'=>$live,'stores'=>$stores->map(fn($s)=>['id'=>$s->id,'name'=>$s->name,'from'=>$s->module_id,'to'=>3]),'items'=>$items->count()]).PHP_EOL;
if(!$live)exit;
DB::transaction(function()use($stores,$items){
 $locked=DB::table('stores')->whereIn('id',[1,2])->lockForUpdate()->get();
 if($locked->every(fn($s)=>(int)$s->module_id===3)){echo "Already moved\n";return;}
 if(!$locked->every(fn($s)=>(int)$s->module_id===1))throw new RuntimeException('Unexpected source module');
 $dir=storage_path('app/private/maintenance');if(!is_dir($dir))mkdir($dir,0700,true);
 $backup=$dir.'/20260926-module-move.json';if(file_exists($backup))throw new RuntimeException('Backup already exists; inspect previous attempt');
 file_put_contents($backup,json_encode(['stores'=>$locked,'items'=>$items,'categories'=>DB::table('categories')->where('module_id',1)->get()],JSON_PRETTY_PRINT),LOCK_EX);chmod($backup,0600);
 $map=[];$clone=function($id)use(&$clone,&$map){if(!$id)return 0;if(isset($map[$id]))return $map[$id];$c=(array)DB::table('categories')->where('id',$id)->first();if(!$c)throw new RuntimeException('Missing category');$parent=$c['parent_id']?$clone($c['parent_id']):0;unset($c['id']);$c['module_id']=3;$c['parent_id']=$parent;$new=DB::table('categories')->insertGetId($c);$map[$id]=$new;
 foreach(DB::table('translations')->where('translationable_type','App\\Models\\Category')->where('translationable_id',$id)->get() as $t){$r=(array)$t;unset($r['id']);$r['translationable_id']=$new;DB::table('translations')->insert($r);}return $new;};
 foreach($items as $item){$categories=json_decode($item->category_ids,true)?:[];foreach($categories as &$c)$c['id']=(string)$clone((int)$c['id']);unset($c);DB::table('items')->where('id',$item->id)->update(['module_id'=>3,'category_id'=>$clone($item->category_id),'category_ids'=>json_encode($categories),'updated_at'=>now()]);}
 DB::table('stores')->whereIn('id',[1,2])->update(['module_id'=>3,'updated_at'=>now()]);
 \Modules\WhatsAppVendorConcierge\app\Models\ConciergeRecoveryAudit::create(['action'=>'move_stores_to_shop','initiated_by'=>'cli','is_dry_run'=>false,'status'=>'success','reason'=>'Owner requested correction of Grocery registrations','details'=>['store_ids'=>[1,2],'target_module_id'=>3,'category_mapping'=>$map],'correlation_id'=>'MODULE-'.Illuminate\Support\Str::uuid()]);
 echo json_encode(['moved'=>[1,2],'category_mapping'=>$map,'backup'=>'storage/app/private/maintenance/20260926-module-move.json']).PHP_EOL;
});
