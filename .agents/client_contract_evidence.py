from pathlib import Path
import subprocess
lines=['Local client endpoint inventory; source comparison only, not installed-app certification.']
needles=['auth/vendor/register','auth/vendor/login','vendor/update-active-status','vendor/update-order-status','vendor/item','delivery-man/update-order-status','delivery-man/update-active-status','items/details','stores/details','baseUrl','BASE_URL']
for name,sub in [('mytijaara-store-app','lib'),('mytijaara-user-app','lib'),('mytijaara-rider-app','lib'),('mytijaara-user-web','src')]:
 root=Path('C:/xampp/htdocs')/name/sub
 if not root.exists():
  lines.append(f'{name}: expected source root missing')
  continue
 for p in sorted(root.rglob('*')):
  if p.suffix not in ['.dart','.js','.ts','.jsx','.tsx']: continue
  for number,line in enumerate(p.read_text(encoding='utf-8',errors='replace').splitlines(),1):
   if any(n in line for n in needles) and (('http' in line or '/api/' in line) or ('baseUrl' in line and 'static' in line)):
    lines.append(f'{p.as_posix()}:{number}: {line.strip()}')
lines.append('\nBackend API differences from starting HEAD:')
lines.append(subprocess.run(['git','diff','b87aa242','--','routes/api','app/Http/Controllers/Api/V1/Auth/VendorLoginController.php','app/Http/Controllers/Api/V1/Vendor/VendorController.php'],capture_output=True,text=True,encoding='utf-8').stdout)
Path('docs/remediation-evidence/client-contracts.txt').write_text('\n'.join(lines)+'\n',encoding='utf-8')
print(f'Recorded {len(lines)} matching declarations/diff sections')
