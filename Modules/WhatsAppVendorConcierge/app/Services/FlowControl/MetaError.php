<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\FlowControl;

class MetaError extends \RuntimeException
{
    public function __construct(public readonly array $safe)
    {
        parent::__construct('Meta API error '.$safe['code'].' / '.$safe['subcode'].' (HTTP '.$safe['http_status'].').');
    }

    public static function fromResponse(int $status, array $error): self
    {
        $code = (int) ($error['code'] ?? $status);
        $sub = (int) ($error['error_subcode'] ?? 0);
        // Business restriction requires the verified health code or specific subcode.
        $limited = $code === 141010 || $sub === 4233020;

        $trace = (string) ($error['fbtrace_id'] ?? '');
        $safeTrace = preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $trace) ? $trace : null;
        foreach (['whatsapp-vendor-concierge.api.access_token', 'whatsapp-vendor-concierge.api.app_secret', 'whatsapp-vendor-concierge.webhook.verify_token', 'whatsapp-vendor-flow.private_key_passphrase'] as $key) {
            $secret = (string) config($key);
            if ($secret !== '' && str_contains($trace, $secret)) {
                $safeTrace = null;
            }
        }

        // Arbitrary Meta strings can reflect credentials/input. Deliberately normalize, not echo them.
        return new self(['http_status' => $status, 'code' => $code, 'subcode' => $sub,
            'title' => $limited ? 'Business verification or Flow eligibility restriction' : 'Meta operation rejected',
            'message' => $limited ? 'Meta considers the target business limited or ineligible. Inspect business verification and account restrictions.' : 'Inspect permissions, resource ownership and validation before retrying.',
            'trace_id' => $safeTrace,
            'retry_safe' => ! $limited && ($status === 429 || $status >= 500),
            'remediation' => $limited ? 'Resolve Business Settings verification or contact Meta support. Repeated publication is not a remedy.' : 'For rate limits or service outages, wait and inspect remote state before retrying.',
        ]);
    }
}
