<?php

namespace Modules\WhatsAppVendorConcierge\app\Http\Controllers\Web;

use Illuminate\Http\Request;
use App\Services\VendorSecurityTokenService;

class FlowPasswordController
{
    public function show()
    {
        $nonce = base64_encode(random_bytes(18));

        return response()->view('whatsapp-vendor-concierge::flow-password', ['nonce' => $nonce])->header('Content-Security-Policy', "default-src 'none'; script-src 'nonce-$nonce'; style-src 'nonce-$nonce'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'")->header('X-Content-Type-Options', 'nosniff');
    }

    public function store(Request $request, VendorSecurityTokenService $tokens)
    {
        // Never flash the token/password into session storage or put them in a URL.
        $v = validator($request->only(['setup_token', 'password', 'password_confirmation']), ['setup_token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'], 'password' => ['required', 'string', 'max:72', 'confirmed', \Illuminate\Validation\Rules\Password::min(8)->mixedCase()->letters()->numbers()->symbols()]]);
        if ($v->fails()) {
            return response()->json(['code' => 'credential_validation', 'message' => 'Use at least 8 characters with upper and lowercase letters, a number and a symbol; confirm the password exactly.'], 422);
        }
        $raw = $request->input('setup_token');
        $record = $tokens->lookup($raw, VendorSecurityTokenService::FLOW_SETUP);
        if (! $record || ! $tokens->completeRegistrationSetup($raw, $request->input('password'))) {
            return response()->json(['code' => 'credential_expired', 'message' => 'This link has expired or was already used. Request a new link in WhatsApp.'], 410);
        }
        $s = \Modules\WhatsAppVendorConcierge\app\Models\VendorFlowSession::where('vendor_id', $record->vendor_id)->first();
        if ($s) {
            app(\Modules\WhatsAppVendorConcierge\app\Services\FlowStateMachine::class)->event($s, 'credential_setup_completed');
        }

        return response()->json(['code' => 'credential_setup_completed', 'message' => 'Password created. Your application remains subject to approval.']);
    }
}
