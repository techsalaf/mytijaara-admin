from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/app/Services/VendorOnboardingService.php');s=p.read_text(encoding='utf-8')
for name,args in [('approveApplication','$storeId, 1'),('rejectApplication','$storeId, 0, $reason')]:
    start=s.index('    public function '+name+'(')
    brace=s.index('{',start); pos=brace+1;depth=1
    while depth:
        if s[pos]=='{':depth+=1
        elif s[pos]=='}':depth-=1
        pos+=1
    s=s[:brace]+'''{
        return app(\\Modules\\WhatsAppVendorConcierge\\app\\Services\\CoreAdapters\\ApplicationDecisionAdapter::class)
            ->decide('''+args+''');
    }'''+s[pos:]
p.write_text(s,encoding='utf-8',newline='\n')
p=Path('Modules/WhatsAppVendorConcierge/tests/Unit/VendorToolsConfirmationTest.php');s=p.read_text(encoding='utf-8');p.write_text(s.replace('Category::create(', 'Category::forceCreate('),encoding='utf-8',newline='\n')
