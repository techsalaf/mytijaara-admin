<?php

// Run only in a bootstrapped console. Never installs production legal content.
if (PHP_SAPI !== 'cli' || ! app()->runningInConsole() || ! app()->environment('local', 'testing')) {
    throw new LogicException('Synthetic policies require a local test console.');
}
$connection = config('database.connections.'.config('database.default'));
$scratch = ($connection['driver'] ?? '') === 'mysql'
    && in_array($connection['host'] ?? '', ['127.0.0.1', 'localhost'], true)
    && preg_match('/^mytijaara_flow_sandbox_[a-f0-9]{16}$/D', $connection['database'] ?? '');
$memory = app()->environment('testing') && ($connection['driver'] ?? '') === 'sqlite' && ($connection['database'] ?? '') === ':memory:';
if (! $scratch && ! $memory) {
    throw new LogicException('Synthetic policies require a disposable loopback sandbox schema or in-memory test database.');
}
$disk = config('registration-policies.archive_disk');
if (! is_string($disk) || $disk === '') {
    throw new LogicException('A dedicated synthetic-policy archive disk is required.');
}
$origin = rtrim((string) (env('SANDBOX_POLICY_DOCUMENT_ORIGIN') ?: 'https://example.test'), '/');
$parts = parse_url($origin);
if (! $parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
    || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
    || ! empty($parts['path'])) {
    throw new LogicException('A reviewed HTTPS synthetic-policy document origin is required.');
}
foreach (['terms', 'privacy'] as $kind) {
    $bytes = file_get_contents(__DIR__.'/policies/'.$kind.'.en.txt');
    $hash = hash('sha256', $bytes);
    $object = 'test-only/'.$kind;
    $url = $origin.'/immutable/'.$hash.'.txt';
    $existing = \App\Models\LegalPolicyVersion::where('policy', $kind)->where('version', 'test-'.$kind.'.en')->first();
    if ($existing) {
        if ($existing->locale !== 'en' || $existing->content_hash !== $hash || $existing->storage_object !== $object
            || $existing->document_url !== $url || $existing->retired_at) {
            throw new LogicException('Existing synthetic policy differs; immutable records will not be overwritten.');
        }
    } else {
        // Existing archive objects are immutable even when the matching database row is absent.
        $archive = \Illuminate\Support\Facades\Storage::disk($disk);
        if ($archive->exists($object)) {
            if (! hash_equals($hash, hash('sha256', $archive->get($object)))) {
                throw new LogicException('Synthetic archive differs; objects will not be overwritten.');
            }
        } elseif (! $archive->put($object, $bytes, ['visibility' => 'private'])) {
            throw new RuntimeException('Synthetic archive write failed.');
        }
        \App\Models\LegalPolicyVersion::create(['policy' => $kind, 'version' => 'test-'.$kind.'.en', 'locale' => 'en',
            'content_hash' => $hash, 'document_url' => $url, 'storage_object' => $object,
            'effective_at' => now('UTC')->subMinute(), 'created_at' => now('UTC')]);
    }
    config(['registration-policies.current.en.'.$kind => 'test-'.$kind.'.en']);
}

return app(\App\Services\RegistrationPolicyService::class)->manifest('en');
