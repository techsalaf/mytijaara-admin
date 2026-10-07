<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Mail\PasswordResetMail;
use App\Models\Vendor;
use App\Services\VendorSecurityTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class VendorPasswordResetController extends Controller
{
    public function reset_password_request(Request $request)
    {
        $validator = Validator::make($request->all(), ['email' => 'required|email']);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        if (! $this->attemptAllowed($request, 'request', 3)) {
            return $this->invalid(429);
        }
        $vendor = Vendor::where('email', $request->email)->first();
        if ($vendor && ! $vendor->getAttribute('deleted_at')) {
            $token = app(VendorSecurityTokenService::class)->issue($vendor, VendorSecurityTokenService::API_RESET);
            if (config('mail.status')) {
                try {
                    Mail::to($vendor->getRawOriginal('email'))->send(new PasswordResetMail($token));
                } catch (\Throwable) {
                    // Never log mail exceptions containing recipient or reset credentials.
                    app(VendorSecurityTokenService::class)->revokePurpose($vendor->id, VendorSecurityTokenService::API_RESET);
                }
            }
        }

        return response()->json(['message' => translate('If the account exists, password reset instructions have been sent.')]);
    }

    public function verify_token(Request $request)
    {
        $validator = Validator::make($request->all(), ['email' => 'required|email', 'reset_token' => 'required|string|max:128']);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        if (! $this->attemptAllowed($request, 'verify', 5)) {
            return $this->invalid(429);
        }
        $vendor = Vendor::where('email', (string) $request->input('email'))->first();
        $record = $vendor ? app(VendorSecurityTokenService::class)->lookup((string) $request->input('reset_token'),
            VendorSecurityTokenService::API_RESET, $vendor->id) : null;

        return $record ? response()->json(['message' => translate('Token verified successfully.')]) : $this->invalid();
    }

    public function reset_password_submit(Request $request)
    {
        if (! $this->attemptAllowed($request, 'verify', 5)) {
            return $this->invalid(429);
        }
        $validator = Validator::make($request->all(), [
            'email' => 'required|email', 'reset_token' => 'required|string|max:128',
            'password' => ['required', Password::min(8)->mixedCase()->letters()->numbers()->symbols()->uncompromised()],
            'confirm_password' => 'required|same:password',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $vendor = Vendor::where('email', $request->email)->first();
        if (! $vendor || ! app(VendorSecurityTokenService::class)->resetPassword($request->reset_token,
            VendorSecurityTokenService::API_RESET, $request->password, $vendor->id)) {
            return $this->invalid();
        }

        return response()->json(['message' => translate('Password changed successfully.')]);
    }

    private function attemptAllowed(Request $request, string $purpose, int $limit): bool
    {
        $email = $request->input('email');
        $key = 'vendor-reset:'.$purpose.':'.hash('sha256', is_string($email) ? strtolower($email) : '');
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return false;
        }
        RateLimiter::hit($key, 600);

        return true;
    }

    private function invalid(int $status = 403)
    {
        return response()->json(['errors' => [['code' => 'reset_token_invalid',
            'message' => translate('Reset authorization is invalid or expired. Please request a new code.')]]], $status);
    }
}
