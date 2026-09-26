<?php
/** Safe release retention. Dry run unless --force; never follows links or touches app/runtime data. */
function removeReleaseTree(string $path): int
{
    if (is_link($path) || is_file($path)) { if (!unlink($path)) throw new RuntimeException('Cannot remove release file'); return 1; }
    $count=0;
    foreach (new FilesystemIterator($path,FilesystemIterator::SKIP_DOTS) as $file) $count+=removeReleaseTree($file->getPathname());
    if (!rmdir($path)) throw new RuntimeException('Cannot remove release directory');
    return $count+1;
}
function pruneReleases(string $application, bool $force=false): array
{
    $app=realpath($application);
    if (!$app || !is_file($app.'/artisan')) throw new RuntimeException('Existing Laravel application required');
    $root=dirname($app).'/.mytijaara-releases';
    if (!file_exists($root)) return ['dry_run'=>!$force,'actions'=>[],'removed_entries'=>0];
    if (is_link($root) || !is_dir($root)) throw new RuntimeException('Unsafe release root');
    $root=realpath($root);
    $lock=fopen($app.'/storage/framework/code-release.lock','c');
    if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('Deployment active; cleanup deferred');
    try {
        $releases=[];$actions=[];$removed=0;
        foreach (new FilesystemIterator($root,FilesystemIterator::SKIP_DOTS) as $entry) {
            $id=$entry->getFilename();$path=$entry->getPathname();
            if (!preg_match('/^[0-9]+-[0-9]+$/D',$id) || $entry->isLink() || !$entry->isDir() || dirname(realpath($path))!==$root) continue;
            if (!is_file($path.'/completed') || is_link($path.'/completed') || is_link($path.'/journal') || is_link($path.'/journal/release.json')) continue;
            $journal=json_decode(@file_get_contents($path.'/journal/release.json')?:'null',true);
            if (($journal['target']??null)!==$app || str_replace('\\','/',$journal['incoming']??'')!==str_replace('\\','/',$path.'/incoming')) continue;
            $releases[]=['id'=>$id,'path'=>$path,'at'=>filemtime($path.'/completed')];
        }
        usort($releases,fn($a,$b)=>$b['at']<=>$a['at']);
        foreach ($releases as $index=>$release) {
            $path=$release['path'];
            // Always remove completed staging packages; retain recent rollback journals.
            if (file_exists($path.'/incoming') || is_link($path.'/incoming')) {
                $actions[]=['release'=>$release['id'],'action'=>'remove_completed_incoming'];
                if ($force) $removed+=removeReleaseTree($path.'/incoming');
            }
            if ($index>=2 && $release['at']<time()-7*86400) {
                $actions[]=['release'=>$release['id'],'action'=>'remove_old_journal'];
                if ($force) $removed+=removeReleaseTree($path);
            }
        }
        return ['dry_run'=>!$force,'actions'=>$actions,'removed_entries'=>$removed];
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    try {echo json_encode(pruneReleases($argv[1]??'',in_array('--force',$argv,true)),JSON_PRETTY_PRINT).PHP_EOL;}
    catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
}
