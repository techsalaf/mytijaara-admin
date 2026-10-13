from pathlib import Path
s=Path('Modules/WhatsAppVendorConcierge/tests/Hardening/VendorApplicationParityTest.php').read_text()
start=s.index("        Schema::create('vendors'")
end=s.index('\n    public function test_',start)
body=s[start:end].rsplit('    }',1)[0]
body=body.replace("$table->text('coordinates')->nullable();", "$table->geometry('coordinates', subtype: 'polygon', srid: 4326)->nullable();")
Path('tests/Architecture/RegistrationSchema.php').write_text('''<?php
namespace Tests\\Architecture;
use Illuminate\\Support\\Facades\\Schema;
use Illuminate\\Database\\Schema\\Blueprint;
final class RegistrationSchema {
    public static function create(): void {
'''+body+'''
        Schema::create('business_settings', function (Blueprint $t) {
            $t->id(); $t->string('key'); $t->text('value')->nullable(); $t->timestamps();
        });
        Schema::create('admins', function (Blueprint $t) {
            $t->id(); $t->integer('role_id'); $t->string('email'); $t->timestamps();
        });
        Schema::create('notification_settings', function (Blueprint $t) {
            $t->id(); $t->string('type'); $t->string('key'); $t->boolean('mail_status')->default(false); $t->timestamps();
        });
    }
}
''')
