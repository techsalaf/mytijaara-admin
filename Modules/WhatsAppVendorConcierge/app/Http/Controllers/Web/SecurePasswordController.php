<?php

namespace Modules\WhatsAppVendorConcierge\app\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Modules\WhatsAppVendorConcierge\app\Services\CredentialTokenService;

class SecurePasswordController extends Controller
{
    public function __construct(private CredentialTokenService $tokens) {}

    public function show(Request $request, string $token)
    {
        $session = $this->tokens->resolve($token);
        $data = $session?->collected_data ?? [];
        return response()->view('whatsapp-vendor-concierge::secure-password', [
            'expired' => !$session, 'success' => false, 'token' => $token,
            'businessName' => $data['business_name'] ?? 'My Store', 'email' => $data['email'] ?? '',
        ], 200, $this->headers());
    }

    public function store(Request $request, string $token)
    {
        $rateKey = 'whatsapp-credential:'.hash('sha256', $token).'|'.$request->ip();
        $limit = max(1, (int) config('whatsapp-vendor-concierge.security.credential_token_rate_limit_per_minute', 10));
        if (RateLimiter::tooManyAttempts($rateKey, $limit)) {
            return $this->failed($request, 429);
        }

        if (!$this->tokens->resolve($token)) {
            return $this->expired($request, $token);
        }
        $validator = Validator::make($request->all(), [
            'password' => ['required', 'string', 'max:72', 'confirmed', Password::min(8)->mixedCase()->letters()->numbers()->symbols()],
        ]);
        if ($validator->fails()) {
            RateLimiter::hit($rateKey, 60);
            if (!$this->tokens->recordFailedAttempt($token)) {
                return $this->failed($request, 422);
            }
            if ($request->expectsJson()) {
                return response()->json(['status' => 'error', 'message' => 'Password does not meet the security requirements.'], 422)
                    ->withHeaders($this->headers());
            }
            return back()->withErrors($validator)->withInput();
        }
        $validated = $validator->validated();
        if (!$this->tokens->consume($token, Hash::make($validated['password']))) {
            return $this->expired($request, $token);
        }
        if ($request->expectsJson()) {
            return response()->json(['status' => 'success', 'message' => 'Password created. Return to WhatsApp to continue.'])->withHeaders($this->headers());
        }
        return response()->view('whatsapp-vendor-concierge::secure-password', [
            'expired' => false, 'success' => true, 'token' => $token,
        ], 200, $this->headers());
    }

    private function expired(Request $request, string $token)
    {
        return $this->failed($request, 410, $token);
    }

    private function failed(Request $request, int $status, ?string $token = null)
    {
        $message = 'We could not complete password setup. Request a new link in WhatsApp.';
        if ($request->expectsJson()) {
            return response()->json(['status' => 'error', 'message' => $message], $status)->withHeaders($this->headers());
        }
        return response()->view('whatsapp-vendor-concierge::secure-password', ['expired' => true, 'token' => $token], $status, $this->headers());
    }

    private function headers(): array
    {
        return ['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow'];
    }
}
