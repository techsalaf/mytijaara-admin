<?php
/** Run from any directory. A source guard complements, rather than replaces, integration tests. */
$root = dirname(__DIR__);
chdir($root);
$manifest = json_decode(file_get_contents(__DIR__.'/core-patches.json'), true, flags: JSON_THROW_ON_ERROR);
$failures = [];
$coreRoots = ['app', 'routes', 'resources', 'config', 'database', 'bootstrap', 'Modules'];
foreach ($coreRoots as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        $path = str_replace('\\', '/', $file->getPathname());
        if (!$file->isFile() || str_starts_with($path, 'bootstrap/cache/') || str_starts_with($path, 'Modules/WhatsAppVendorConcierge/') || !preg_match('/\.(php|json)$/', $path)) continue;
        if (preg_match('/WhatsAppVendorConcierge|WhatsAppMedia|OnboardingSession|WhatsAppContact|MediaPolicyService/', file_get_contents($path))) {
            $failures[] = "Forbidden core dependency: $path";
        }
    }
}
// Compare the PR/push base, not a commit title heuristic. Every commit in this
// integration is subject to the same core restriction.
$base = $argv[1] ?? $manifest['baseline'];
if (preg_match('/^0+$/D', $base)) $base = $manifest['baseline'];
if (!preg_match('/^[a-zA-Z0-9_\/.^-]+$/D', $base)) throw new RuntimeException('Invalid comparison ref');
exec('git diff --name-only '.escapeshellarg($base).' -- app routes resources config database bootstrap', $changed, $code);
if ($code !== 0) throw new RuntimeException('Cannot compare Git baseline');
exec('git ls-files --others --exclude-standard -- app routes resources config database bootstrap', $newCoreFiles, $code);
if ($code !== 0) throw new RuntimeException('Cannot inventory untracked core files');
$changed = array_unique(array_merge($changed, $newCoreFiles));
$platformBaseline = $manifest['platform_baseline'] ?? null;
if ($platformBaseline !== null && !preg_match('/^[a-f0-9]{40}$/D', $platformBaseline)) throw new RuntimeException('Platform baseline must be an immutable commit');
// An explicitly accepted platform import is not a blanket path exemption.
// Only files still identical to that snapshot qualify; all dependency scans run.
$platformChanges = [];
if ($platformBaseline !== null) {
    exec('git diff --name-only '.escapeshellarg($platformBaseline).' -- app routes resources config database bootstrap', $platformChanges, $code);
    if ($code !== 0) throw new RuntimeException('Cannot compare platform baseline');
}
foreach ($changed as $path) {
    if (isset($manifest['patches'][$path]) || in_array($path, $manifest['separate_addon_changes'], true)) continue;
    if ($platformBaseline !== null && !in_array($path, $platformChanges, true) && !in_array($path, $newCoreFiles, true)) continue;
    // Permit restoration only when the complete file matches the recorded import.
    exec('git diff --quiet '.escapeshellarg($manifest['baseline']).' -- '.escapeshellarg($path), $unused, $different);
    if ($different !== 0 || in_array($path, $newCoreFiles, true)) $failures[] = "Unapproved core patch: $path";
}
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator('Modules/WhatsAppVendorConcierge/app', FilesystemIterator::SKIP_DOTS)) as $file) {
    $path = str_replace('\\', '/', $file->getPathname());
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $source = file_get_contents($path);
    $directWrite = preg_match('/\b(?:Store|Item|Order)::(?:create|insert|update|upsert|destroy)\s*\(|new\s+(?:Store|Item|Order)\b|\$(?:store|item|order)->(?:save|update|delete|increment|decrement)\s*\(/', $source);
    // Include literal query-builder chains and aliases imported for core models.
    $models = ['Store', 'Item', 'Order'];
    preg_match_all('/use\s+App\\\\Models\\\\(?:Store|Item|Order)\s+as\s+(\w+)\s*;/', $source, $aliases);
    $models = array_merge($models, $aliases[1]);
    $modelNames = implode('|', array_map(fn ($name) => preg_quote($name, '/'), $models));
    $chainWrite = preg_match('/(?:\b(?:'.$modelNames.')::|DB::table\(\s*[\'"](?:stores|items|orders)[\'"]\s*\))[^;]*?(?:->|::)(?:save|update|insert|upsert|delete|increment|decrement|create|destroy)\s*\(/s', $source);
    if (($directWrite || $chainWrite)
        && !in_array($path, $manifest['protected_write_owners'], true)) {
        $failures[] = "Protected write outside reviewed adapter: $path";
    }
}
if ($failures) { fwrite(STDERR, implode(PHP_EOL, $failures).PHP_EOL); exit(1); }
echo "Core boundary passed: no forbidden host dependencies; changed core paths and protected write owners checked.\n";
