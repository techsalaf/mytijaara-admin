<?php

namespace App\Services;

use App\Models\Store;
use App\Models\Vendor;
use App\Models\VendorEmployee;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class VendorAuthenticationService
{
    public function __construct(private VendorAuthenticationEligibility $policy, private VendorSecurityTokenService $tokens) {}

    /** Verifies credentials without creating a session; status and issuance share locks. */
    public function authenticate(string $email, string $password, string $type, string $channel = 'api', bool $allowPreActivation = false): ?array
    {
        if (! in_array($type, ['owner', 'employee'], true)) {
            return null;
        }
        $class = $type === 'owner' ? Vendor::class : VendorEmployee::class;
        $candidate = $class::withoutGlobalScopes()->where('email', $email)->first();
        if (! $candidate || ! Hash::check($password, (string) $candidate->password)) {
            return null;
        }

        try {
            return DB::transaction(function () use ($candidate, $class, $password, $type, $channel, $allowPreActivation) {
                $store = Store::withoutGlobalScopes()->when($type === 'owner', fn ($q) => $q->where('vendor_id', $candidate->id),
                    fn ($q) => $q->whereKey($candidate->store_id))->orderBy('id')->lockForUpdate()->first();
                $vendor = Vendor::withoutGlobalScopes()->lockForUpdate()->find($type === 'owner' ? $candidate->id : $candidate->vendor_id);
                $principal = $type === 'owner' ? $vendor : $class::withoutGlobalScopes()->lockForUpdate()->find($candidate->id);
                if (! $principal || ! Hash::check($password, (string) $principal->password)) {
                    return null;
                }
                $decision = $this->policy->evaluate($vendor, $store, $channel, $type === 'employee' ? $principal : null);
                $result = compact('decision', 'principal', 'vendor', 'store');
                if (! $decision->eligible) {
                    if ($channel === 'api' && $allowPreActivation && $decision->preActivationAllowed) {
                        $result['pre_activation_token'] = $this->tokens->issue($vendor, VendorSecurityTokenService::PRE_ACTIVATION, $store);
                    }

                    return $result;
                }
                if ($channel === 'api') {
                    $result['token'] = bin2hex(random_bytes(60));
                    $principal->auth_token = $result['token'];
                    $principal->save();
                    $this->tokens->revokePurpose($vendor->id, VendorSecurityTokenService::PRE_ACTIVATION);
                }

                return $result;
            }, 3);
        } catch (QueryException) {
            // Do not propagate SQL bindings containing a freshly generated bearer.
            throw new \RuntimeException('Vendor authentication persistence failed.');
        }
    }
}
