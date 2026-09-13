<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use Illuminate\Support\Facades\DB;
use Modules\WhatsAppVendorConcierge\app\Jobs\ContinueCredentialOnboarding;
use Modules\WhatsAppVendorConcierge\app\Models\CredentialToken;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;

class CredentialTokenService
{
    public function issue(OnboardingSession $session): string
    {
        $origin = rtrim((string) config('app.url'), '/');
        $parts = parse_url($origin);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \LogicException('Credential creation requires a trusted HTTPS APP_URL.');
        }
        $token = bin2hex(random_bytes(32));
        DB::transaction(function () use ($session, $token) {
            $locked = OnboardingSession::lockForUpdate()->findOrFail($session->id);
            abort_unless($locked->status === 'started' && !$locked->isExpired()
                && $locked->current_step === 'account_password', 410);
            CredentialToken::where('onboarding_session_id', $locked->id)
                ->whereNull('consumed_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            CredentialToken::create([
                'onboarding_session_id' => $locked->id,
                'token_hash' => hash('sha256', $token),
                'purpose' => 'vendor_onboarding_password',
                'expires_at' => now()->addMinutes(15),
                'creation_metadata' => ['channel' => 'whatsapp', 'version' => 1],
            ]);
            $data = $locked->collected_data ?? [];
            unset($data['_pwd_token_hash'], $data['_pwd_token_expires_at']);
            $locked->update(['collected_data' => $data]);
        });
        return $origin . route('whatsapp.onboarding.password', ['token' => $token], false);
    }

    public function resolve(string $token): ?OnboardingSession
    {
        $record = $this->validToken($token)->first();
        if (!$record) {
            return null;
        }
        return OnboardingSession::whereKey($record->onboarding_session_id)
            ->where('status', 'started')->where('current_step', 'account_password')
            ->where('expires_at', '>', now())->first();
    }

    /**
     * Atomically count a failed password submission. A reached limit revokes
     * the token, so a brute-force attempt can never become a valid credential.
     */
    public function recordFailedAttempt(string $token): bool
    {
        return DB::transaction(function () use ($token) {
            $record = CredentialToken::where('token_hash', hash('sha256', $token))
                ->where('purpose', 'vendor_onboarding_password')->lockForUpdate()->first();
            if (!$record || !$this->isUsable($record)) {
                return false;
            }

            $attempts = $record->attempt_count + 1;
            $record->update([
                'attempt_count' => $attempts,
                'revoked_at' => $attempts >= $this->maxAttempts() ? now() : null,
            ]);

            return $attempts < $this->maxAttempts();
        });
    }

    public function consume(string $token, string $passwordHash): bool
    {
        $candidate = $this->validToken($token)->first();
        if (!$candidate) {
            return false;
        }
        return DB::transaction(function () use ($candidate, $passwordHash) {
            // Regeneration and consumption share the same lock order.
            $session = OnboardingSession::lockForUpdate()->find($candidate->onboarding_session_id);
            if (!$session || $session->status !== 'started' || $session->isExpired()
                || $session->current_step !== 'account_password') {
                return false;
            }
            $record = CredentialToken::whereKey($candidate->id)->lockForUpdate()->first();
            if (!$record || !$this->isUsable($record)) {
                return false;
            }
            $record->update(['consumed_at' => now()]);
            $data = $session->collected_data ?? [];
            unset($data['_pwd_token_hash'], $data['_pwd_token_expires_at'], $data['password']);
            $data['password_hash'] = $passwordHash;
            $data['has_password'] = true;
            $step = !empty($data['_in_review']) ? 'review_submit' : 'store_branding';
            $session->update(['collected_data' => $data, 'current_step' => $step, 'last_activity_at' => now()]);
            WhatsAppConversation::where('onboarding_session_id', $session->id)
                ->where('contact_id', $session->contact_id)->where('state', 'onboarding_active')
                ->update(['current_step' => $step]);
            ContinueCredentialOnboarding::dispatch($session->id, $step)->afterCommit();
            return true;
        });
    }

    private function validToken(string $token)
    {
        return CredentialToken::where('token_hash', hash('sha256', $token))
            ->where('purpose', 'vendor_onboarding_password')->whereNull('revoked_at')
            ->whereNull('consumed_at')->where('expires_at', '>', now())
            ->where('attempt_count', '<', $this->maxAttempts());
    }

    private function isUsable(CredentialToken $record): bool
    {
        return !$record->revoked_at && !$record->consumed_at && $record->expires_at->isFuture()
            && $record->attempt_count < $this->maxAttempts();
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('whatsapp-vendor-concierge.security.credential_token_max_attempts', 5));
    }
}
