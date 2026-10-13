from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge')
f=p/'app/Providers/WhatsAppVendorConciergeServiceProvider.php';s=f.read_text(encoding='utf-8').replace('$this->commands([','$this->commands([\n            \\Modules\\WhatsAppVendorConcierge\\app\\Console\\Commands\\LaunchTaxonomyCommand::class,',1);f.write_text(s,encoding='utf-8')
f=p/'routes/web.php';s=f.read_text(encoding='utf-8');s+='''
Route::middleware(['web','admin',\\Modules\\WhatsAppVendorConcierge\\app\\Http\\Middleware\\AuthorizeWhatsAppOperations::class])->prefix('admin/whatsapp/product-drafts')->name('admin.whatsapp.product-drafts.')->group(function(){
 $controller=\\Modules\\WhatsAppVendorConcierge\\app\\Http\\Controllers\\Admin\\ProductDraftController::class;
 Route::get('/',[$controller,'index'])->name('index');
 Route::get('/{draft}',[$controller,'show'])->name('show');
 Route::get('/{draft}/image',[$controller,'image'])->name('image');
 Route::post('/{draft}',[$controller,'action'])->name('action');
});
''';f.write_text(s,encoding='utf-8')
f=p/'resources/views/admin/operations_center/index.blade.php';s=f.read_text(encoding='utf-8');pos=s.index('@section(\'content\')')+len('@section(\'content\')');s=s[:pos]+'''\n<div class="content container-fluid pb-0"><a class="btn btn-outline-primary" href="{{route('admin.whatsapp.product-drafts.index')}}">Product drafts &amp; missing category images</a></div>'''+s[pos:];f.write_text(s,encoding='utf-8')
