<?php
namespace Tests\Architecture;
use PHPUnit\Framework\TestCase;

final class CodeReleaseTest extends TestCase
{
    public function test_deployment_and_rollback_preserve_runtime_files_and_restore_code(): void
    {
        require_once __DIR__.'/../../scripts/safe-code-release.php';
        $root = sys_get_temp_dir().'/code-release-'.bin2hex(random_bytes(6));
        foreach (['target', 'incoming', 'journal'] as $dir) mkdir($root.'/'.$dir, 0755, true);
        file_put_contents($root.'/target/artisan', 'old');
        file_put_contents($root.'/target/.env', 'preserve');
        file_put_contents($root.'/incoming/artisan', 'new');
        file_put_contents($root.'/incoming/new.php', '<?php // new');
        ob_start();
        try {
            runCodeRelease(['test', 'prepare', $root.'/target', $root.'/journal', $root.'/incoming']);
            runCodeRelease(['test', 'apply', $root.'/target', $root.'/journal']);
            $this->assertSame('new', file_get_contents($root.'/target/artisan'));
            $this->assertSame('preserve', file_get_contents($root.'/target/.env'));
            runCodeRelease(['test', 'rollback', $root.'/target', $root.'/journal']);
            $this->assertSame('old', file_get_contents($root.'/target/artisan'));
            $this->assertFileDoesNotExist($root.'/target/new.php');
            $this->assertSame('preserve', file_get_contents($root.'/target/.env'));
        } finally {
            ob_end_clean();
            $this->removeFixture($root);
        }
    }

    public function test_runtime_paths_and_traversal_are_rejected(): void
    {
        require_once __DIR__.'/../../scripts/safe-code-release.php';
        foreach (['../artisan', '.env', '.env.production', '.envrc', 'bootstrap/cache/config.php', 'storage/logs/app.log', 'public/storage/photo.jpg', 'config/system-addons.php'] as $path) {
            try { releasePath(sys_get_temp_dir(), $path); $this->fail('Accepted '.$path); }
            catch (\RuntimeException) { $this->addToAssertionCount(1); }
        }
    }

    public function test_changed_code_is_refused_before_any_apply_or_rollback_writes(): void
    {
        require_once __DIR__.'/../../scripts/safe-code-release.php';
        $root = sys_get_temp_dir().'/code-release-'.bin2hex(random_bytes(6));
        foreach (['target', 'incoming', 'journal'] as $dir) mkdir($root.'/'.$dir, 0755, true);
        foreach (['target' => 'old', 'incoming' => 'new'] as $dir => $value) {
            file_put_contents($root.'/'.$dir.'/artisan', $value);
            file_put_contents($root.'/'.$dir.'/another.php', $value);
        }
        ob_start();
        try {
            runCodeRelease(['test', 'prepare', $root.'/target', $root.'/journal', $root.'/incoming']);
            file_put_contents($root.'/target/another.php', 'operator-edit');
            foreach (['apply', 'rollback'] as $command) {
                try { runCodeRelease(['test', $command, $root.'/target', $root.'/journal']); $this->fail('Concurrent edit accepted'); }
                catch (\RuntimeException $e) { $this->assertStringContainsString('Concurrent code change', $e->getMessage()); }
                $this->assertSame('old', file_get_contents($root.'/target/artisan'));
                $this->assertSame('operator-edit', file_get_contents($root.'/target/another.php'));
            }
        } finally { ob_end_clean(); $this->removeFixture($root); }
    }

    public function test_unreviewed_obsolete_class_is_preserved_and_blocks_preparation(): void
    {
        require_once __DIR__.'/../../scripts/safe-code-release.php';
        $root = sys_get_temp_dir().'/code-release-'.bin2hex(random_bytes(6));
        foreach (['target/app/Services', 'incoming', 'journal'] as $dir) mkdir($root.'/'.$dir, 0755, true);
        file_put_contents($root.'/target/artisan', 'old');
        file_put_contents($root.'/incoming/artisan', 'new');
        $obsolete = $root.'/target/app/Services/ProductMutationService.php';
        file_put_contents($obsolete, 'operator-customization');
        try {
            try { runCodeRelease(['test', 'prepare', $root.'/target', $root.'/journal', $root.'/incoming']); $this->fail('Unknown obsolete hash accepted'); }
            catch (\RuntimeException $e) { $this->assertStringContainsString('Obsolete file differs', $e->getMessage()); }
            $this->assertSame('operator-customization', file_get_contents($obsolete));
            $this->assertSame('old', file_get_contents($root.'/target/artisan'));
            $this->assertFileDoesNotExist($root.'/journal/release.json');
        } finally { $this->removeFixture($root); }
    }

    public function test_release_roots_cannot_overlap(): void
    {
        require_once __DIR__.'/../../scripts/safe-code-release.php';
        $this->assertTrue(releaseRootsOverlap('/srv/app', '/srv/app/incoming'));
        $this->assertTrue(releaseRootsOverlap('/srv/app/journal', '/srv/app'));
        $this->assertTrue(releaseRootsOverlap('/srv/app', '/srv/app'));
        $this->assertFalse(releaseRootsOverlap('/srv/app', '/srv/app-release'));
    }

    private function removeFixture(string $root): void
    {
        if (!str_starts_with($root, sys_get_temp_dir().'/code-release-')) throw new \LogicException('Unexpected fixture path');
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($root);
    }
}
