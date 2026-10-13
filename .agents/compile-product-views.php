<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach(['index','show'] as $name){$source=file_get_contents('Modules/WhatsAppVendorConcierge/resources/views/admin/product-drafts/'.$name.'.blade.php');file_put_contents('.agents/draft-'.$name.'-compiled.php',app('blade.compiler')->compileString($source));echo $name.PHP_EOL;}
