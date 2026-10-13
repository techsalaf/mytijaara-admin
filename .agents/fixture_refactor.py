from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/tests/Hardening/VendorApplicationParityTest.php');s=p.read_text(encoding='utf-8')
start=s.index('    protected function setUp(): void');end=s.index('    public function test_',start)
prefix=s[:start].replace('class VendorApplicationParityTest', 'abstract class ApplicationFixtureTestCase')
Path('Modules/WhatsAppVendorConcierge/tests/Hardening/ApplicationFixtureTestCase.php').write_text(prefix+s[start:end]+'}\n',encoding='utf-8',newline='\n')
p.write_text(s[:start].replace('extends HardeningTestCase','extends ApplicationFixtureTestCase')+s[end:],encoding='utf-8',newline='\n')
for name in ['MaintenanceCommandsTest','PreferencesAndSupportTest']:
    p=Path('Modules/WhatsAppVendorConcierge/tests/Hardening/'+name+'.php');s=p.read_text(encoding='utf-8')
    s=s.replace('extends TestCase', 'extends ApplicationFixtureTestCase').replace('    use DatabaseTransactions;','')
    p.write_text(s,encoding='utf-8',newline='\n')
