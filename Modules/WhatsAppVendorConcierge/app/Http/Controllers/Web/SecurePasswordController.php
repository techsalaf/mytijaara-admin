<?php

namespace Modules\WhatsAppVendorConcierge\app\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService;
use Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway;

class SecurePasswordController extends Controller
{
    /**
     * Display the secure password creation page.
     */
    public function show(Request $request, string $token)
    {
        $session = $this->resolveValidSession($token);

        if (!$session) {
            return view('whatsapp-vendor-concierge::secure-password', [
                'expired' => true,
                'token' => $token,
            ]);
        }

        $data = $session->collected_data ?? [];
        $businessName = $data['business_name'] ?? 'My Store';
        $email = $data['email'] ?? '';
        $ownerName = trim(($data['f_name'] ?? '') . ' ' . ($data['l_name'] ?? ''));

        return view('whatsapp-vendor-concierge::secure-password', [
            'expired' => false,
            'success' => false,
            'token' => $token,
            'session' => $session,
            'businessName' => $businessName,
            'email' => $email,
            'ownerName' => $ownerName,
        ]);
    }

    /**
     * Handle the secure password submission over HTTPS.
     */
    public function store(Request $request, string $token)
    {
        $session = $this->resolveValidSession($token);

        if (!$session) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This secure link has expired or has already been used. Please request a new link in WhatsApp.',
                ], 410);
            }

            return view('whatsapp-vendor-concierge::secure-password', [
                'expired' => true,
                'token' => $token,
            ]);
        }

        $request->validate([
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
                Password::min(8)->mixedCase()->letters()->numbers()->symbols(),
            ],
        ], [
            'password.required' => 'Please enter a password.',
            'password.min' => 'Password must be at least 8 characters.',
            'password.confirmed' => 'Password confirmation does not match.',
        ]);

        // Securely hash password with bcrypt - zero plaintext is ever logged or sent to WhatsApp
        $passwordHash = Hash::make($request->password);

        // Update session collected data
        $data = $session->collected_data ?? [];
        $data['password_hash'] = $passwordHash;
        $data['has_password'] = true;
        unset($data['_pwd_token_hash']);
        unset($data['_pwd_token_expires_at']);

        $isInReview = !empty($data['_in_review']);
        $nextStep = $isInReview ? 'review_submit' : 'store_branding';

        $session->update([
            'collected_data' => $data,
            'current_step' => $nextStep,
            'last_activity_at' => now(),
        ]);

        // Invalidate single-use token immediately
        Cache::forget('wa_pwd_token:' . hash('sha256', $token));

        Log::info('Vendor password set securely via HTTPS', [
            'session_id' => $session->id,
            'contact_id' => $session->contact_id,
            'in_review' => $isInReview,
        ]);

        // Automatically resume WhatsApp onboarding flow
        try {
            $contact = $session->contact;
            if ($contact) {
                $conversation = WhatsAppConversation::where('contact_id', $contact->id)->first();
                if ($conversation) {
                    $conversation->update([
                        'state' => 'onboarding_active',
                        'current_step' => $nextStep,
                    ]);

                    $gateway = app(WhatsAppGateway::class);
                    $onboardingService = app(VendorOnboardingService::class);

                    if ($isInReview) {
                        $gateway->sendTextMessage(
                            $contact->phone_number,
                            "🔒 *Password Updated Successfully!*\n\nYour vendor dashboard password has been updated and securely encrypted."
                        );
                        $onboardingService->sendStepPrompt($conversation, $contact, 'review_submit', $gateway);
                    } else {
                        $gateway->sendTextMessage(
                            $contact->phone_number,
                            "🔒 *Password Created Successfully!*\n\nYour vendor dashboard credentials are encrypted and secured."
                        );
                        // Send next step prompt (store_branding: mandatory 1:1 logo)
                        $onboardingService->sendStepPrompt($conversation, $contact, 'store_branding', $gateway);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('Failed to notify WhatsApp after secure password creation', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'status' => 'success',
                'message' => 'Password created successfully! Return to WhatsApp to continue.',
            ]);
        }

        return view('whatsapp-vendor-concierge::secure-password', [
            'expired' => false,
            'success' => true,
            'token' => $token,
            'session' => $session,
            'businessName' => $data['business_name'] ?? 'My Store',
            'email' => $data['email'] ?? '',
            'ownerName' => trim(($data['f_name'] ?? '') . ' ' . ($data['l_name'] ?? '')),
        ]);
    }

    /**
     * Resolve and validate session by token.
     */
    protected function resolveValidSession(string $token): ?OnboardingSession
    {
        if (empty($token) || strlen($token) < 16) {
            return null;
        }

        $tokenHash = hash('sha256', $token);

        // 1. Try cache lookup first
        $sessionId = Cache::get('wa_pwd_token:' . $tokenHash);
        if ($sessionId) {
            $session = OnboardingSession::find($sessionId);
            if ($session && $this->isSessionTokenValid($session, $tokenHash)) {
                return $session;
            }
        }

        // 2. Fallback to database lookup
        $sessions = OnboardingSession::whereNotNull('collected_data')
            ->where('status', 'started')
            ->latest('last_activity_at')
            ->take(50)
            ->get();

        foreach ($sessions as $session) {
            if ($this->isSessionTokenValid($session, $tokenHash)) {
                return $session;
            }
        }

        return null;
    }

    /**
     * Check if session token hash matches and has not expired.
     */
    protected function isSessionTokenValid(OnboardingSession $session, string $tokenHash): bool
    {
        $data = $session->collected_data ?? [];
        $storedHash = $data['_pwd_token_hash'] ?? null;
        $expiresAt = $data['_pwd_token_expires_at'] ?? null;

        if (!$storedHash || !hash_equals($storedHash, $tokenHash)) {
            return false;
        }

        if (!$expiresAt || Carbon::parse($expiresAt)->isPast()) {
            return false;
        }

        return true;
    }
}
