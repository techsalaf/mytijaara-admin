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
foreach ($changed as $path) {
    if (isset($manifest['patches'][$path]) || in_array($path, $manifest['separate_addon_changes'], true)) continue;
    // Permit restoration only when the complete file matches the recorded import.
    exec('git diff --quiet '.escapeshellarg($manifest['baseline']).' -- '.escapeshellarg($path), $unused, $different);
    if ($different !== 0 || in_array($path, $newCoreFiles, true)) $failures[] = "Unapproved core patch: $path";
}
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator('Modules/WhatsAppVendorConcierge/app', FilesystemIterator::SKIP_DOTS)) as $file) {
    $path = str_replace('\\', '/', $file->getPathname());
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $source = file_get_contents($path);
    if (preg_match('/\b(?:Store|Item|Order)::(?:create|insert|update|upsert|destroy)\s*\(|new\s+(?:Store|Item|Order)\b|\$(?:store|item|order)->(?:save|update|delete|increment|decrement)\s*\(/', $source)
        && !in_array($path, $manifest['protected_write_owners'], true)) {
        $failures[] = "Protected write outside reviewed adapter: $path";
    }
}
if ($failures) { fwrite(STDERR, implode(PHP_EOL, $failures).PHP_EOL); exit(1); }
echo "Core boundary passed: no forbidden host dependencies; changed core paths and protected write owners checked.\n";
