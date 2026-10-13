from pathlib import Path
root = Path('Modules/WhatsAppVendorConcierge/tests')
p = root/'Hardening/VendorOperationsTest.php'
s = p.read_text(encoding='utf-8')
start = s.index('class VendorOperationsTest extends HardeningTestCase')
tests = s.index('    public function test_product_mutation_service_creates_product_with_canonical_rules')
base = s[:tests].replace('class VendorOperationsTest extends HardeningTestCase', 'abstract class OperationsFixtureTestCase extends HardeningTestCase') + '}\n'
(root/'Hardening/OperationsFixtureTestCase.php').write_text(base, encoding='utf-8')
p.write_text(s[:start] + 'class VendorOperationsTest extends OperationsFixtureTestCase\n{\n' + s[tests:], encoding='utf-8')
mapping = {
 'AdminApprovalNotificationTest': 'ApplicationFixtureTestCase',
 'VendorStatusNotificationJobTest': 'ApplicationFixtureTestCase',
 'HumanSupportEscalationTest': 'HardeningTestCase',
 'WhatsAppFlowTest': 'HardeningTestCase',
 'WhatsAppApiControllerTest': 'HardeningTestCase',
 'WhatsAppVendorConciergeTest': 'ApplicationFixtureTestCase',
 'VendorToolsConfirmationTest': 'OperationsFixtureTestCase',
}
for name, parent in mapping.items():
 p = root/'Unit'/f'{name}.php'
 if not p.exists(): continue
 s = p.read_text(encoding='utf-8')
 s = s.replace('use Tests\\TestCase;', f'use Modules\\WhatsAppVendorConcierge\\tests\\Hardening\\{parent};')
 s = s.replace(f'class {name} extends TestCase', f'class {name} extends {parent}')
 s = s.replace('use Illuminate\\Foundation\\Testing\\DatabaseTransactions;\n', '').replace('    use DatabaseTransactions;\n', '')
 p.write_text(s, encoding='utf-8')
