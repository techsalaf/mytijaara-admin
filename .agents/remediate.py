from pathlib import Path
import subprocess, re, json

root = Path.cwd()
def historical(path, ref='679034e2^'):
    return subprocess.check_output(['git', 'show', f'{ref}:{path}']).decode().replace('\r\n', '\n')
def write(path, content):
    p = root / path
    p.parent.mkdir(parents=True, exist_ok=True)
    p.write_text(content, encoding='utf-8', newline='\n')
def method(text, name):
    start = re.search(r'    public function '+name+r'\(', text).start()
    end = re.search(r'\n    (?:public|private|protected) function ', text[start+1:])
    return start, start+1+end.start() if end else len(text)

# Capture the reviewed pre-extraction implementations, not a presumed stock release.
targets = [('app/Http/Controllers/VendorController.php','store'),
 ('app/Http/Controllers/Api/V1/Vendor/VendorController.php','active_status'),
 ('app/Http/Controllers/Vendor/BusinessSettingsController.php','active_status')]
for path, name in targets:
    old = historical(path, '679034e2^' if name == 'store' else 'bf5ca49f')
    a,b = method(old,name)
    write('tests/Architecture/fixtures/'+Path(path).parent.name+'-'+Path(path).stem+'-'+name+'.txt', old[a:b])

write('docs/remediation-evidence/baseline-limitations.md', '''# Baseline execution

Starting HEAD: b87aa24269a0074a546c79e224691508222fd0f0.
The pre-existing untracked audit report is preserved without edits.
The whole module test run stalled without a result and was interrupted. A receipt
probe reached the prepared HTTP test but did not complete. Focused runs and their
raw logs are recorded separately. An interrupted run is not a passing baseline.
Local PHP reports 8.5.9, outside Composer's configured PHP 8.3 platform.
No verified licensed admin source archive was found in the repository root or
top-level Downloads listing; Rental and React archives are not an admin baseline.
''')

if '--apply' not in __import__('sys').argv:
    raise SystemExit(0)

for path,name in targets:
    old=historical(path, '679034e2^' if name == 'store' else 'bf5ca49f'); current=Path(path).read_text(encoding='utf-8')
    a,b=method(old,name); c,d=method(current,name)
    write(path,current[:c]+old[a:b]+current[d:])

moves = {'app/DTOs/VendorApplicationDTO.php':'Modules/WhatsAppVendorConcierge/app/DTOs/VendorApplicationDTO.php'}
for name in ['VendorApplicationService','ProductMutationService','OrderMutationService','StoreAvailabilityService']:
    moves['app/Services/'+name+'.php']='Modules/WhatsAppVendorConcierge/app/Services/CoreAdapters/'+name+'.php'
for src,dst in moves.items():
    content=Path(src).read_text(encoding='utf-8')
    ns='Modules\\WhatsAppVendorConcierge\\app\\'+('DTOs' if '/DTOs/' in src else 'Services\\CoreAdapters')
    content=re.sub(r'namespace App\\(?:DTOs|Services);',lambda _: 'namespace '+ns+';',content)
    write(dst,content)
    Path(src).unlink()
for p in Path('Modules/WhatsAppVendorConcierge').rglob('*.php'):
    s=p.read_text(encoding='utf-8'); t=s
    for src,dst in moves.items():
        old=src[:-4].replace('/','\\').replace('app\\','App\\',1)
        new=dst[:-4].replace('/','\\')
        t=t.replace(old,new)
    if t!=s: write(p,t)

p=Path('resources/views/admin-views/vendor/pending_requests.blade.php')
s=p.read_text(encoding='utf-8')
s=re.sub(r'\n[ \t]*@if\(class_exists\([^\n]*WhatsAppContact[^\n]*\n.*?@endif', '',s,flags=re.S)
write(p,s)
p=Path('app/Models/Category.php'); s=p.read_text(encoding='utf-8')
s=s.replace("        'name',\n",'',1).replace("return (string) ($value ?? '');","return $value;")
write(p,s)
