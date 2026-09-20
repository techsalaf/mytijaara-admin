<?php
// Process-isolated branch fixture. Does not change the installed add-on's info.php.
namespace App\Http\Controllers;

function addon_published_status($moduleName): int
{
    return $moduleName === 'Rental' ? 1 : \addon_published_status($moduleName);
}
