import subprocess, re, concurrent.futures
from pathlib import Path
files = subprocess.check_output(['git','diff','--cached','--name-only','--diff-filter=ACMR','-z']).decode().split('\0')
files = [f for f in files if f]
findings=[]
for f in files:
    if f.startswith(('.agents/','storage/app/public/')) or f == 'config/system-addons.php' or (f.startswith('.env') and f != '.env.example'):
        findings.append(f + ': prohibited local file')
    raw=subprocess.check_output(['git','show',':'+f])
    if len(raw)>100*1024*1024: findings.append(f+': exceeds GitHub file limit')
    if b'\0' in raw: continue
    for pattern in [rb'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----',rb'\bgh[pousr]_[A-Za-z0-9]{30,}',rb'\bsk-(?:proj-)?[A-Za-z0-9_-]{40,}',rb'\bAKIA[A-Z0-9]{16}\b']:
        if re.search(pattern,raw): findings.append(f+': possible credential; inspect privately')
print('Staged files:',len(files),'Credential/file findings:',len(findings))
for finding in findings: print(finding)
php=[f for f in files if f.endswith('.php') and not f.endswith('.blade.php')]
def lint(f):
    p=subprocess.run(['C:/xampp/php/php.exe','-l',f],capture_output=True,text=True)
    return f,p.returncode,p.stdout+p.stderr
with concurrent.futures.ThreadPoolExecutor(max_workers=8) as executor:
    results=list(executor.map(lint,php))
failed=[r for r in results if r[1]]
Path('.agents/all-changes-lint.txt').write_text('\n'.join(r[2] for r in results),encoding='utf-8')
print('PHP syntax:',len(php),'checked;',len(failed),'failed')
for f,code,out in failed: print(f,out)
raise SystemExit(1 if findings or failed else 0)
