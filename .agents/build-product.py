from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge')
def put(n,s):
 f=p/n;f.parent.mkdir(parents=True,exist_ok=True);f.write_text(s,encoding='utf-8')
put('database/migrations/2026_09_26_200000_create_product_listing_drafts.php', '''<?php
use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('whatsapp_product_drafts',function(Blueprint $t){
   $t->id();$t->unsignedBigInteger('conversation_id')->index();$t->unsignedBigInteger('contact_id');$t->unsignedBigInteger('store_id')->index();$t->unsignedBigInteger('module_id');
   $t->string('status')->default('active')->index();$t->string('step')->default('image');$t->json('data');$t->json('sources');$t->json('vision')->nullable();$t->json('errors')->nullable();$t->json('processed_messages')->nullable();$t->unsignedInteger('stalled_turns')->default(0);$t->boolean('needs_attention')->default(false);$t->unsignedBigInteger('item_id')->nullable()->unique();$t->timestamps();
  });
  Schema::create('whatsapp_taxonomy_keys',function(Blueprint $t){$t->id();$t->unsignedBigInteger('module_id');$t->string('stable_key');$t->unsignedBigInteger('category_id');$t->unique(['module_id','stable_key']);$t->timestamps();});
 }
 public function down(): void {Schema::dropIfExists('whatsapp_taxonomy_keys');Schema::dropIfExists('whatsapp_product_drafts');}
};
''')
put('app/Models/ProductListingDraft.php','''<?php
namespace Modules\\WhatsAppVendorConcierge\\app\\Models;
use Illuminate\\Database\\Eloquent\\Model;
class ProductListingDraft extends Model {
 protected $table='whatsapp_product_drafts';
 protected $guarded=['id'];
 protected $casts=['data'=>'array','sources'=>'array','vision'=>'array','errors'=>'array','processed_messages'=>'array','needs_attention'=>'boolean'];
 public function store(){return $this->belongsTo(\\App\\Models\\Store::class);}
 public function conversation(){return $this->belongsTo(WhatsAppConversation::class);}
}
''')
put('app/Services/ProductFieldMap.php','''<?php
namespace Modules\\WhatsAppVendorConcierge\\app\\Services;
use App\\Models\\{Store,Category};
use Illuminate\\Support\\Facades\\DB;
class ProductFieldMap {
 const TYPES=['grocery','ecommerce','food','pharmacy'];
 public function steps(Store $store): array {
  if(!in_array($store->module->module_type,self::TYPES,true))return [];
  $type=$store->module->module_type;$steps=['image','name','category_id','subcategory_id','description'];
  if(\\App\\CentralLogics\\Helpers::storeCategoryStatus() && DB::table('store_categories')->where('store_id',$store->id)->exists())$steps[]='store_category_id';
  if(config("module.$type.unit"))$steps[]='unit_id';
  $steps=array_merge($steps,['price','discount']);
  if(config("module.$type.stock"))$steps[]='stock';
  if($type==='food')$steps=array_merge($steps,['veg','available_time_starts','available_time_ends','add_ons']);
  if($type==='pharmacy')$steps[]='is_prescription_required';
  return array_merge($steps,['additional_media','review']);
 }
 public function categories(Store $s, ?int $parent=null) {
  return Category::withoutGlobalScopes()->where('module_id',$s->module_id)->where('status',1)->when($parent!==null,fn($q)=>$q->where('parent_id',$parent))->orderBy('name');
 }
 public function optional(string $field,Store $s): bool {
  return in_array($field,['subcategory_id','discount','unit_id','add_ons','additional_media'],true)||($field==='image' && $s->module->module_type==='food');
 }
}
''')
