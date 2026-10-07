<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use Illuminate\Support\Facades\Http;

class FlowMetaClient
{
    public function request(string $method, string $path, array $data = [], ?string $asset = null): array
    {
        if (! preg_match('~^[0-9]+(?:/(?:flows|assets|publish|messages))?$~D', $path)) {
            throw new \InvalidArgumentException('Invalid Meta resource.');
        }
        $version = (string) config('whatsapp-vendor-flow.graph_version', 'v26.0');
        if (! preg_match('/^v[0-9]+\.0$/D', $version)) {
            throw new \LogicException('Invalid Graph API version.');
        }
        $req = Http::withToken((string) config('whatsapp-vendor-concierge.api.access_token'))->connectTimeout(5)->timeout(20)->withoutRedirecting();
        if ($asset !== null) {
            $req = $req->attach('file', $asset, 'flow.json', ['Content-Type' => 'application/json']);
        }
        try {
            $response = $method === 'GET' ? $req->get('https://graph.facebook.com/'.$version.'/'.$path, $data) : $req->post('https://graph.facebook.com/'.$version.'/'.$path, $data);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Meta network request failed.');
        }
        $body = $response->json();
        if (! $response->successful() || isset($body['error']) || ! is_array($body)) {
            $code = (int) ($body['error']['code'] ?? $response->status());
            $sub = (int) ($body['error']['error_subcode'] ?? 0);
            // Never echo request payload, bearer, endpoint URL, media URL or arbitrary reflected Meta text.
            $hint = match ($response->status()) {
                401 => ' Verify sandbox access-token validity.',403 => ' Verify account permissions and resource ownership.',429 => ' Rate limited; retry later.',default => ''
            };
            throw new \RuntimeException('Meta API error '.$code.' / '.$sub.' (HTTP '.$response->status().').'.$hint);
        }

        return $body;
    }

    public function offer(string $sender, string $flowId, string $token): array
    {
        return $this->request('POST', (string) config('whatsapp-vendor-concierge.api.phone_number_id').'/messages', [
            'messaging_product' => 'whatsapp', 'to' => $sender, 'type' => 'interactive', 'interactive' => ['type' => 'flow', 'body' => ['text' => 'Register your store in a short secure form. Your application remains subject to approval.'],
                'action' => ['name' => 'flow', 'parameters' => ['flow_message_version' => '3', 'flow_id' => $flowId, 'flow_token' => $token, 'flow_cta' => 'Register store', 'flow_action' => 'data_exchange', 'mode' => config('whatsapp-vendor-flow.mode', 'draft')]]]]);
    }

    public function text(string $sender, string $text): array
    {
        return $this->request('POST', (string) config('whatsapp-vendor-concierge.api.phone_number_id').'/messages', ['messaging_product' => 'whatsapp', 'to' => $sender, 'type' => 'text', 'text' => ['body' => $text, 'preview_url' => false]]);
    }
}
