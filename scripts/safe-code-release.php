<?php
/** File-only deployment with a recoverable journal. Never manages runtime data or databases. */
function releasePath(string $root, string $relative): string
{
    $relative = str_replace('\\', '/', $relative);
    if ($relative === '' || str_starts_with($relative, '/') || preg_match('~(^|/)\.\.?(/|$)|:~', $relative)) throw new RuntimeException('Unsafe relative path');
    if (preg_match('~^(\.env[^/]*$|storage(?:/|$)|public/storage(?:/|$)|bootstrap/cache(?:/|$)|\.git(?:/|$)|config/system-addons\.php$)~', $relative)) throw new RuntimeException('Protected runtime path: '.$relative);
    $path = $root;
    foreach (explode('/', $relative) as $part) {
        $path .= DIRECTORY_SEPARATOR.$part;
        if (is_link($path)) throw new RuntimeException('Symlink in managed path: '.$relative);
    }
    return $path;
}
function releaseCopy(string $from, string $to): void
{
    if (!is_dir(dirname($to)) && !mkdir(dirname($to), 0755, true)) throw new RuntimeException('Cannot create release directory');
    // A failed write must not leave a partial destination that cannot be rolled
    // back against the journal's old/new hashes.
    $temporary = dirname($to).'/.release-'.bin2hex(random_bytes(12));
    try {
        if (!@copy($from, $temporary)) {
            $reason = error_get_last()['message'] ?? 'unknown filesystem error';
            throw new RuntimeException('Cannot copy code file '.$from.' to '.$to.': '.$reason);
        }
        if (PHP_OS_FAMILY !== 'Windows' && !chmod($temporary, fileperms($from) & 0777)) throw new RuntimeException('Cannot preserve code permissions');
        if (!rename($temporary, $to)) throw new RuntimeException('Cannot replace code file');
    } finally {
        if (is_file($temporary)) unlink($temporary);
    }
}
function releaseRootsOverlap(string $a, string $b): bool
{
    $a = str_replace('\\', '/', $a);
    $b = str_replace('\\', '/', $b);
    if (PHP_OS_FAMILY === 'Windows') { $a = strtolower($a); $b = strtolower($b); }
    return $a === $b || str_starts_with($a, $b.'/') || str_starts_with($b, $a.'/');
}
function runCodeRelease(array $argv): void
{
    [$script, $command, $targetArg, $journalArg] = array_pad($argv, 4, null);
    $target = realpath($targetArg ?? '');
    if (!$target || !is_file($target.'/artisan')) throw new RuntimeException('Target must be an existing Laravel root');
    $journal = realpath($journalArg ?? '');
    if (!$journal || releaseRootsOverlap($journal, $target)) throw new RuntimeException('Journal and application roots must be disjoint');
    $index = $journal.'/release.json';
    if ($command === 'prepare') {
        if (file_exists($index)) throw new RuntimeException('Release journal already exists');
        $incoming = realpath($argv[4] ?? '');
        if (!$incoming || releaseRootsOverlap($incoming, $target) || releaseRootsOverlap($incoming, $journal) || !is_file($incoming.'/artisan')) throw new RuntimeException('Disjoint incoming Laravel package required');
        $entries = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($incoming, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) continue;
            if (!$file->isFile() || $file->isLink()) throw new RuntimeException('Only regular package files are allowed');
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($incoming)+1));
            $destination = releasePath($target, $relative);
            $entry = ['new' => hash_file('sha256', $file->getPathname()), 'old' => is_file($destination) ? hash_file('sha256', $destination) : null];
            // Unchanged code needs neither replacement nor a rollback copy.
            // Keeping it out of the journal avoids duplicating the full vendor
            // tree on every small release on quota-limited shared hosting.
            if ($entry['new'] === $entry['old']) continue;
            $entries[$relative] = $entry;
        }
        $obsolete = json_decode(file_get_contents(__DIR__.'/obsolete-core-files.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($obsolete as $relative => $expectedHash) {
            $destination = releasePath($target, $relative);
            if (isset($entries[$relative])) throw new RuntimeException('Obsolete file also exists in incoming package');
            if (!is_file($destination)) continue;
            if (!hash_equals($expectedHash, hash_file('sha256', $destination))) throw new RuntimeException('Obsolete file differs from reviewed version: '.$relative);
            $entries[$relative] = ['old' => $expectedHash, 'new' => null];
        }
        foreach ($entries as $relative => $entry) {
            if ($entry['old'] !== null) releaseCopy(releasePath($target, $relative), releasePath($journal.'/before', $relative));
        }
        file_put_contents($index, json_encode(['target' => $target, 'incoming' => $incoming, 'entries' => $entries], JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR), LOCK_EX);
        echo "Release journal prepared; no live code changed.\n";
        return;
    }
    $data = json_decode(file_get_contents($index), true, flags: JSON_THROW_ON_ERROR);
    if ($data['target'] !== $target) throw new RuntimeException('Journal belongs to another target');
    if (!in_array($command, ['apply', 'rollback'], true)) throw new RuntimeException('Expected prepare, apply or rollback');
    // Validate every file before changing any file; stop if code changed since preparation.
    foreach ($data['entries'] as $relative => $entry) {
        $destination = releasePath($target, $relative);
        $actual = is_file($destination) ? hash_file('sha256', $destination) : null;
        if (!in_array($actual, [$entry['old'], $entry['new']], true)) throw new RuntimeException('Concurrent code change: '.$relative);
        $source = $command === 'apply' ? releasePath($data['incoming'], $relative) : releasePath($journal.'/before', $relative);
        $expected = $command === 'apply' ? $entry['new'] : $entry['old'];
        if ($expected !== null && (!is_file($source) || !hash_equals($expected, hash_file('sha256', $source)))) throw new RuntimeException('Release source changed: '.$relative);
    }
    foreach ($data['entries'] as $relative => $entry) {
        $destination = releasePath($target, $relative);
        $expected = $command === 'apply' ? $entry['new'] : $entry['old'];
        if ($expected === null) {
            if (is_file($destination) && !unlink($destination)) throw new RuntimeException('Cannot remove managed code file');
        } else {
            releaseCopy($command === 'apply' ? releasePath($data['incoming'], $relative) : releasePath($journal.'/before', $relative), $destination);
        }
    }
    echo "Code $command finished. Runtime data and database unchanged.\n";
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try { runCodeRelease($argv); } catch (Throwable $e) { fwrite(STDERR, $e->getMessage()."\n"); exit(1); }
}
