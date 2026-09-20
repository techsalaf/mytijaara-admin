<?php
namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class CoreBoundaryGuardTest extends TestCase
{
    public function test_guard_rejects_reverse_dependencies_unlisted_core_files_and_bypassed_adapters(): void
    {
        $root = sys_get_temp_dir().'/core-boundary-'.bin2hex(random_bytes(6));
        foreach (['scripts', 'app', 'routes', 'resources', 'config', 'database', 'bootstrap',
            'Modules/WhatsAppVendorConcierge/app', 'Modules/OtherAddon'] as $directory) mkdir($root.'/'.$directory, 0755, true);
        try {
            copy(__DIR__.'/../../scripts/check-core-boundary.php', $root.'/scripts/check-core-boundary.php');
            $this->runCommand(['git', 'init', '--quiet'], $root);
            file_put_contents($root.'/app/Host.php', '<?php // host');
            $this->runCommand(['git', 'add', 'app/Host.php'], $root);
            $this->runCommand(['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', 'commit', '--quiet', '-m', 'fixture'], $root);
            $baseline = trim($this->runCommand(['git', 'rev-parse', 'HEAD'], $root)->getOutput());
            file_put_contents($root.'/scripts/core-patches.json', json_encode([
                'baseline' => $baseline, 'patches' => [], 'separate_addon_changes' => [], 'protected_write_owners' => [],
            ], JSON_THROW_ON_ERROR));
            $this->assertSame(0, $this->guard($root)->getExitCode());
            foreach (['app/Host.php', 'Modules/OtherAddon/Consumer.php'] as $file) {
                file_put_contents($root.'/'.$file, '<?php use Modules\\WhatsAppVendorConcierge\\app\\Models\\WhatsAppContact;');
                $result = $this->guard($root);
                $this->assertSame(1, $result->getExitCode());
                $this->assertStringContainsString('Forbidden core dependency', $result->getErrorOutput());
                if ($file === 'app/Host.php') file_put_contents($root.'/'.$file, '<?php // host');
                else unlink($root.'/'.$file);
            }
            file_put_contents($root.'/app/Unapproved.php', '<?php // new core dependency');
            $this->assertStringContainsString('Unapproved core patch', $this->guard($root)->getErrorOutput());
            unlink($root.'/app/Unapproved.php');
            file_put_contents($root.'/Modules/WhatsAppVendorConcierge/app/Bypass.php', '<?php $item->save();');
            $this->assertStringContainsString('Protected write outside reviewed adapter', $this->guard($root)->getErrorOutput());
            foreach ([
                "<?php DB::table('orders')->where('id', 1)->update(['order_status' => 'delivered']);",
                "<?php use App\\Models\\Item as Product; Product::whereKey(1)->delete();",
            ] as $source) {
                file_put_contents($root.'/Modules/WhatsAppVendorConcierge/app/Bypass.php', $source);
                $this->assertStringContainsString('Protected write outside reviewed adapter', $this->guard($root)->getErrorOutput());
            }
        } finally {
            if (!str_starts_with($root, sys_get_temp_dir().'/core-boundary-')) throw new \LogicException('Unexpected fixture');
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                if ($file->isDir()) rmdir($file->getPathname());
                else { chmod($file->getPathname(), 0600); unlink($file->getPathname()); }
            }
            rmdir($root);
        }
    }

    private function runCommand(array $command, string $root): Process
    {
        $process = new Process($command, $root);
        $process->mustRun();
        return $process;
    }

    private function guard(string $root): Process
    {
        $process = new Process([PHP_BINARY, 'scripts/check-core-boundary.php'], $root);
        $process->run();
        return $process;
    }
}
