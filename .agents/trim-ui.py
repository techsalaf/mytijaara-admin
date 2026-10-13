from pathlib import Path
for name in ['app/Services/ProductListingFlow.php','app/Services/ProductListingPresenter.php','tests/Hardening/VendorOperationsTest.php']:
 p=Path('Modules/WhatsAppVendorConcierge')/name;p.write_text(p.read_text().rstrip()+'\n')
