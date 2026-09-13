<?php

namespace Modules\WhatsAppVendorConcierge\app\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
        if (!$this->tokens->resolve($token)) {
            return $this->expired($request, $token);
        }
        $this->tokens->recordAttempt($token);
        $validated = $request->validate([
            'password' => ['required', 'string', 'max:72', 'confirmed', Password::min(8)->mixedCase()->letters()->numbers()->symbols()],
        ]);
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
        if ($request->expectsJson()) {
            return response()->json(['status' => 'error', 'message' => 'Link expired or used. Request a new link in WhatsApp.'], 410)->withHeaders($this->headers());
        }
        return response()->view('whatsapp-vendor-concierge::secure-password', ['expired' => true, 'token' => $token], 410, $this->headers());
    }

    private function headers(): array
    {
        return ['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow'];
    }
}
