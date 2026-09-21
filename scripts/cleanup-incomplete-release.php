<?php
/** Remove only explicitly identified, never-applied release preparations. */
function cleanupIncompleteRelease(string $application, string $id): void
{
    $app = realpath($application);
    if (!$app || !is_file($app.'/artisan')) throw new RuntimeException('Existing application root required');
    if (!preg_match('/^[0-9]+-[0-9]+$/D', $id)) throw new RuntimeException('Invalid release identifier');
    $parent = dirname($app).'/.mytijaara-releases';
    if (!file_exists($parent)) return;
    if (is_link($parent) || !is_dir($parent)) throw new RuntimeException('Unexpected release root');
    $parent = realpath($parent);
    $candidate = $parent.'/'.$id;
    if (!file_exists($candidate) && !is_link($candidate)) return;
    if (is_link($candidate) || !is_dir($candidate)) throw new RuntimeException('Unexpected release directory');
    $candidate = realpath($candidate);
    if (dirname($candidate) !== $parent || $candidate === $app || str_starts_with($app, $candidate.DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Release must be a separate direct child of the release root');
    }
    // Apply requires this journal. Its existence means rollback data must stay.
    if (is_link($candidate.'/journal') || file_exists($candidate.'/journal/release.json') || is_link($candidate.'/journal/release.json')) {
        throw new RuntimeException('Preserving release with a journal: '.$id);
    }
    foreach (scandir($candidate) as $entry) {
        if (!in_array($entry, ['.', '..', 'incoming', 'journal'], true)) throw new RuntimeException('Unexpected release contents');
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($candidate, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        // Do not follow symlinks, including links to application/runtime data.
        if ($file->isLink() || !$file->isDir()) {
            if (!unlink($file->getPathname())) throw new RuntimeException('Cannot remove incomplete release file');
        } elseif (!rmdir($file->getPathname())) throw new RuntimeException('Cannot remove incomplete release directory');
    }
    if (!rmdir($candidate)) throw new RuntimeException('Cannot remove incomplete release');
    echo 'Removed incomplete release preparation: '.$id.PHP_EOL;
}
if (isset($argv) && (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ || ($_SERVER['SCRIPT_FILENAME'] ?? '') === '/dev/stdin')) {
    try {
        if (count($argv) < 3) throw new RuntimeException('Usage: cleanup-incomplete-release.php APP_ROOT RELEASE_ID ...');
        foreach (array_slice($argv, 2) as $id) cleanupIncompleteRelease($argv[1], $id);
    } catch (Throwable $error) { fwrite(STDERR, $error->getMessage().PHP_EOL); exit(1); }
}
