from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/tests/Hardening/ProductListingFlowTest.php');s=p.read_text(encoding='utf-8').replace('parent::setUp();Schema::create',"parent::setUp();\\Illuminate\\Support\\Facades\\Storage::fake('local');Schema::create",1);p.write_text(s,encoding='utf-8')
