<?php

namespace Modules\WhatsAppVendorConcierge\app\DTOs;

final readonly class FlowSubmission
{
    public function __construct(public string $sender, public string $messageId, public string $flowId, public string $definitionVersion, public string $tokenHash, public array $response, public \DateTimeImmutable $receivedAt) {}

    public static function fromMessage(array $message): self
    {
        $raw = $message['interactive']['nfm_reply']['response_json'] ?? null;
        if (! is_string($raw) || strlen($raw) > 65536) {
            throw new \InvalidArgumentException('Malformed Flow response.');
        }
        $p = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($p) || ! is_string($p['flow_token'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $p['flow_token'])) {
            throw new \InvalidArgumentException('Invalid Flow correlation.');
        }
        if (($p['submitted'] ?? null) !== true || ! is_string($p['flow_id'] ?? null) || strlen($p['flow_id']) > 100 || ! preg_match('/^[0-9]+$/D', $p['flow_id']) || ! is_string($p['definition_version'] ?? null) || strlen($p['definition_version']) > 80 || ! is_string($message['from'] ?? null)) {
            throw new \InvalidArgumentException('Invalid Flow identity fields.');
        }
        $sender = \App\DTOs\VendorSelfRegistrationInput::normalizePhone((string) ($message['from'] ?? ''));
        if (! preg_match('/^[0-9]{10,20}$/D', $sender) || ! is_string($message['id'] ?? null) || $message['id'] === '' || strlen($message['id']) > 191) {
            throw new \InvalidArgumentException('Invalid Flow envelope.');
        }

        return new self($sender, $message['id'], (string) ($p['flow_id'] ?? ''), (string) ($p['definition_version'] ?? ''), hash('sha256', $p['flow_token']), array_intersect_key($p, array_flip(['submitted', 'flow_id', 'definition_version'])), new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }
}
