from pathlib import Path
import subprocess
import json
import xml.etree.ElementTree as ET

def git(*args):
    return subprocess.check_output(['git', *args])

# Remove only known automatic test translations, and refuse any other delta.
path = Path('resources/lang/en/messages.php')
original = git('show', 'HEAD:resources/lang/en/messages.php')
current = path.read_bytes().replace(b'\r\n', b'\n')
for line in [
    b"  'loyalty_point_not_enabled' => 'Loyalty point not enabled',\n",
    b"  'Not found' => 'Not found',\n",
    b"  'You must select a package' => 'You must select a package',\n",
    b"  'You must select a pickup zone' => 'You must select a pickup zone',\n",
]:
    if line not in original.replace(b'\r\n', b'\n'):
        current = current.replace(line, b'')
assert current == original.replace(b'\r\n', b'\n'), 'Unexpected translation change: review manually'
path.write_bytes(original)

evidence = Path('docs/remediation-evidence')
manifest = json.loads(Path('scripts/core-patches.json').read_text())
(evidence/'retained-core-patch.diff').write_bytes(git('diff', '--unified=0', 'bf5ca49f', '--', *manifest['patches']))
refs = subprocess.run(['rg', '-n', 'WhatsAppVendorConcierge|WhatsAppMedia|OnboardingSession|WhatsAppContact|MediaPolicyService', 'app', 'routes', 'resources', 'config', 'database', 'bootstrap', '--glob', '!bootstrap/cache/**'], capture_output=True)
assert refs.returncode == 1, refs.stdout.decode(errors='replace')
(evidence/'core-reference-final.txt').write_text('rg source scan: no forbidden core-to-module references. Exit 1 means zero matches.\n', encoding='utf-8')
assert not git('diff', '--name-only', 'b87aa242', '--', 'database/migrations', 'Modules/WhatsAppVendorConcierge/database/migrations').strip()
(evidence/'migration-impact.txt').write_text('No migration changes from b87aa242. Existing module migrations remain required for installation.\n', encoding='utf-8')
results = []
for name in ['module-final-complete', 'architecture-verified', 'rental-registration', 'canonical-product-validation']:
    suite = ET.parse(evidence/f'{name}.xml').getroot().find('testsuite')
    assert all(suite.get(key) == '0' for key in ['errors', 'failures', 'skipped']), name
    results.append(name + ': ' + ', '.join(f'{key}={suite.get(key)}' for key in ['tests', 'assertions', 'errors', 'failures', 'skipped']))
(evidence/'final-results.txt').write_text('Application code commit: '+git('rev-parse', 'HEAD').decode().strip()+'\nLocal runtime: PHP 8.5.9 / MariaDB 10.4.32.\n'+'\n'.join(results)+'\nModule PHPUnit deprecations: 50; architecture: 1.\n', encoding='utf-8')
print('\n'.join(results))
