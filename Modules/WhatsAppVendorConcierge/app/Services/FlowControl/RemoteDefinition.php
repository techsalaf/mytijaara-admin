<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\FlowControl;

use Illuminate\Support\Facades\Http;
use Modules\WhatsAppVendorConcierge\app\Services\FlowDefinitionValidator;
use Modules\WhatsAppVendorConcierge\app\Services\FlowMetaClient;

class RemoteDefinition
{
    public function compare(string $flow): array
    {
        $assets = app(FlowMetaClient::class)->request('GET', $flow.'/assets');
        $matches = collect($assets['data'] ?? [])->where('asset_type', 'FLOW_JSON')->where('name', 'flow.json');
        if ($matches->count() !== 1) {
            throw new Failure('A single FLOW_JSON asset is required for comparison.');
        }
        $url = $matches->first()['download_url'] ?? '';
        $parts = parse_url($url);
        // This production account's authenticated assets endpoint supplies this Meta host.
        // Never forward the Graph bearer to a signed asset URL, follow redirects or accept arbitrary hosts.
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'mmg.whatsapp.net' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            throw new Failure('Unapproved Meta asset download origin. Inspect the documented host before adding it.');
        }
        $r = Http::connectTimeout(5)->timeout(20)->withoutRedirecting()->withOptions(['stream' => true])->get($url);
        if (! $r->successful()) {
            throw new Failure('Remote asset download failed.');
        }
        $stream = $r->toPsrResponse()->getBody();
        $bytes = '';
        try {
            while (! $stream->eof()) {
                $chunk = $stream->read(65536);
                if ($chunk === '' && ! $stream->eof()) {
                    throw new Failure('Partial remote asset download.');
                }
                $bytes .= $chunk;
                if (strlen($bytes) > 1048576) {
                    throw new Failure('Remote definition exceeds the reviewed limit.');
                }
            }
        } finally {
            $stream->close();
        }
        $remote = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        $validator = app(FlowDefinitionValidator::class);
        $validator->validate($remote);
        $local = $validator->validate();
        $paths = [];
        $this->diff($local, $remote, '', $paths);

        return ['flow_id' => $flow, 'remote_asset_hash' => hash('sha256', $bytes), 'local_asset_hash' => hash_file('sha256', $validator->path()), 'definitions_match' => $local == $remote, 'changed_paths' => array_slice($paths, 0, 100), 'diff_count' => count($paths), 'comparison' => 'Validated remote bytes compared structurally; no remote values or signed URLs exposed.'];
    }

    private function diff(mixed $a, mixed $b, string $path, array &$out): void
    {
        if (is_array($a) && is_array($b)) {
            foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $key) {
                $next = $path.'.'.$key;
                if (! array_key_exists($key, $a) || ! array_key_exists($key, $b)) {
                    $out[] = $next;
                } else {
                    $this->diff($a[$key], $b[$key], $next, $out);
                }
            }
        } elseif ($a !== $b) {
            $out[] = $path;
        }
    }
}
