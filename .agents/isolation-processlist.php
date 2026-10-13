<?php
$p = new PDO('mysql:host=127.0.0.1', 'root', '');
foreach ($p->query('SHOW FULL PROCESSLIST') as $r) {
 if (str_starts_with($r['db'] ?? '', 'mytijaara_isolation_')) echo json_encode(array_intersect_key($r,array_flip(['Id','db','Command','Time','State','Info']))).PHP_EOL;
}
