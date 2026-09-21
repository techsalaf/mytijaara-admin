<?php
namespace Tests\Architecture;
use PHPUnit\Framework\TestCase;
final class IncompleteReleaseCleanupTest extends TestCase
{
    public function test_only_incomplete_explicit_release_is_removed(): void
    {
        require_once __DIR__.'/../../scripts/cleanup-incomplete-release.php';
        $root = sys_get_temp_dir().'/incomplete-release-'.bin2hex(random_bytes(6));
        foreach (['app', '.mytijaara-releases/123-1/incoming', '.mytijaara-releases/123-1/journal', '.mytijaara-releases/124-1/journal'] as $dir) mkdir($root.'/'.$dir, 0755, true);
        file_put_contents($root.'/app/artisan', 'live');
        file_put_contents($root.'/.mytijaara-releases/123-1/incoming/artisan', 'unused');
        file_put_contents($root.'/.mytijaara-releases/124-1/journal/release.json', '{}');
        ob_start();
        try {
            cleanupIncompleteRelease($root.'/app', '123-1');
            cleanupIncompleteRelease($root.'/app', '123-1'); // Retry tolerates absence.
            $this->assertDirectoryDoesNotExist($root.'/.mytijaara-releases/123-1');
            foreach (['124-1', '../app'] as $id) {
                try { cleanupIncompleteRelease($root.'/app', $id); $this->fail('Unsafe release cleanup accepted'); }
                catch (\RuntimeException) { $this->addToAssertionCount(1); }
            }
            $this->assertFileExists($root.'/.mytijaara-releases/124-1/journal/release.json');
            $this->assertSame('live', file_get_contents($root.'/app/artisan'));
        } finally {
            ob_end_clean();
            if (!str_starts_with($root, sys_get_temp_dir().'/incomplete-release-')) throw new \LogicException('Unexpected fixture');
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($root);
        }
    }
}
