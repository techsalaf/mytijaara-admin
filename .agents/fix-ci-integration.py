from pathlib import Path
import subprocess,re,json
def old(p,ref='66d23ea4'):
    return subprocess.check_output(['git','show',ref+':'+p]).decode()
def method(s,name):
    return re.search(r'    (?:public|private|protected) function '+name+r'\(.*?(?=\n    (?:public|private|protected) function |\Z)',s,re.S).group()
p=Path('app/Http/Controllers/Admin/VendorController.php')
s=p.read_text(encoding='utf-8'); previous=old(p.as_posix())
for name in ['status','update_application','updateVendorApplication']:
    s=s.replace(method(s,name),method(previous,name),1)
p.write_text(s,encoding='utf-8')
# Only approval-form hunks differ in these three templates; preserve other update content.
for file in ['pending_requests.blade.php','deny_requests.blade.php','view/partials/_header.blade.php']:
    p=Path('resources/views/admin-views/vendor')/file
    delta=subprocess.check_output(['git','diff','66d23ea4','d76e3efc','--',str(p)]).decode()
    assert all(x in delta for x in ['diff --git','@@'])
    p.write_text(old(p.as_posix()),encoding='utf-8')
p=Path('routes/admin.php');s=p.read_text(encoding='utf-8')
s=s.replace("Route::get('update-application/{id}/{status}', 'VendorController@update_application')->name('application');", "Route::get('update-application/{id}/{status}', fn () => redirect()->route('admin.store.pending-requests'));\n                Route::post('update-application/{id}/{status}', 'VendorController@update_application')->whereIn('status', ['0', '1'])->name('application');")
p.write_text(s,encoding='utf-8')
# Fixture SRID must match the host's explicitly configured coordinate SRID.
p=Path('tests/Architecture/RegistrationSchema.php');s=p.read_text();s=s.replace('srid: 4326','srid: POINT_SRID');p.write_text(s)
p=Path('tests/Architecture/MySqlHostRegistrationTest.php');s=p.read_text();s=s.replace(")',4326)\")", ")',\".POINT_SRID.\")\")");p.write_text(s)
# Public registration's imported Service mail branch is intentional, not a concierge extraction.
p=Path('tests/Architecture/fixtures/Controllers-VendorController-store.txt')
p.write_text(method(Path('app/Http/Controllers/VendorController.php').read_text(),'store'))
p=Path('scripts/core-patches.json');data=json.loads(p.read_text());data['platform_baseline']='d76e3efcd7ded59f15c4dce180483cd18b959d1e';data['platform_baseline_reason']='User-authorized platform import, separate from the WhatsApp core patches. Only exact unchanged file content at this commit is accepted; new edits remain gated.';p.write_text(json.dumps(data,indent=2)+'\n')
p=Path('.gitattributes');s=p.read_text();p.write_text(s.replace('* text=auto','* text=auto\nscripts/*.sh text eol=lf'))
