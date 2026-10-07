<?php

namespace App\Services;

use App\DTOs\VendorSelfRegistrationInput;
use App\Models\Store;
use App\Models\Vendor;
use App\Models\VendorEmployee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class VendorSecurityTokenService
{
    public const PRE_ACTIVATION = 'subscription_setup';

    public const API_RESET = 'password_reset_api';

    public const WEB_RESET = 'password_reset_web';

    public const FLOW_SETUP = 'vendor_registration_setup';

    public function issue(Vendor $vendor, string $purpose, ?Store $store = null): string
    {
        if (! in_array($purpose, [self::PRE_ACTIVATION, self::API_RESET, self::WEB_RESET, self::FLOW_SETUP], true)) {
            throw new \InvalidArgumentException('Unsupported vendor security token purpose.');
        }
        $raw = '';
        DB::transaction(function () use ($vendor, $store, $purpose, &$raw) {
            $lockedVendor = Vendor::withoutGlobalScopes()->whereKey($vendor->id)->lockForUpdate()->firstOrFail();
            // The controller sends to this same locked subject, never a stale recipient.
            $vendor->setRawAttributes($lockedVendor->getAttributes(), true);
            do {
                $raw = $purpose === self::API_RESET ? (string) random_int(100000, 999999) : bin2hex(random_bytes(32));
                if ($purpose === self::WEB_RESET) {
                    $raw = 'vr1_'.$raw;
                }
            } while (DB::table('vendor_security_tokens')->where('token_hash', $this->digest($raw, $purpose, $vendor->id))->exists());
            $this->revokePurpose($vendor->id, $purpose);
            DB::table('vendor_security_tokens')->insert([
                'vendor_id' => $vendor->id, 'store_id' => $store?->id, 'purpose' => $purpose,
                'token_hash' => $this->digest($raw, $purpose, $vendor->id),
                'subject_hash' => $this->subjectHash($vendor),
                'expires_at' => now()->addMinutes($purpose === self::API_RESET ? 10 : 15),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return $raw;
    }

    public function lookup(string $raw, string $purpose, ?int $vendorId = null): ?object
    {
        // API OTP hashes are vendor-bound, keyed HMACs to resist offline enumeration.
        if ($purpose === self::API_RESET && ! $vendorId) {
            return null;
        }
        if (strlen($raw) > 128 || $raw === '') {
            return null;
        }

        $record = DB::table('vendor_security_tokens')->where('purpose', $purpose)
            ->where('token_hash', $this->digest($raw, $purpose, $vendorId))
            ->when($vendorId, fn ($q) => $q->where('vendor_id', $vendorId))
            ->whereNull('consumed_at')->whereNull('revoked_at')->where('expires_at', '>', now())->first();
        $vendor = $record ? Vendor::withoutGlobalScopes()->find($record->vendor_id) : null;

        return $record && $vendor && $this->matchesSubject($record, $vendor) ? $record : null;
    }

    public function resetPassword(string $raw, string $purpose, string $password, ?int $vendorId = null): bool
    {
        if (! in_array($purpose, [self::API_RESET, self::WEB_RESET], true)) {
            return false;
        }

        return $this->changePassword($raw, $purpose, $password, $vendorId);
    }

    public function completeRegistrationSetup(string $raw, string $password): bool
    {
        return $this->changePassword($raw, self::FLOW_SETUP, $password);
    }

    private function changePassword(string $raw, string $purpose, string $password, ?int $vendorId = null): bool
    {
        if (! in_array($purpose, [self::API_RESET, self::WEB_RESET, self::FLOW_SETUP], true)) {
            return false;
        }
        $record = $this->lookup($raw, $purpose, $vendorId);
        if (! $record) {
            return false;
        }
        $hash = Hash::make($password);

        return DB::transaction(function () use ($record, $hash, $raw, $purpose) {
            // Same order as application decisions and login: store, vendor, token.
            $store = Store::withoutGlobalScopes()->where('vendor_id', $record->vendor_id)->orderBy('id')->lockForUpdate()->first();
            $vendor = Vendor::withoutGlobalScopes()->lockForUpdate()->find($record->vendor_id);
            $locked = DB::table('vendor_security_tokens')->where('id', $record->id)->lockForUpdate()->first();
            if (($purpose === self::FLOW_SETUP && (! $store || $store->id != $record->store_id)) || ! $vendor || ! $store || $vendor->getAttribute('deleted_at') || $store->getAttribute('deleted_at')
                || ! $locked || $locked->consumed_at || $locked->revoked_at || $locked->expires_at <= now()->toDateTimeString()
                || ! $this->matchesSubject($locked, $vendor)
                || ! hash_equals($locked->token_hash, $this->digest($raw, $purpose, $vendor->id))) {
                return false;
            }
            // Password reset is allowed for pending applicants; never changes approval.
            $vendor->password = $hash;
            $vendor->auth_token = null;
            $vendor->remember_token = bin2hex(random_bytes(30));
            $vendor->login_remember_token = bin2hex(random_bytes(30));
            $vendor->save();
            VendorEmployee::withoutGlobalScopes()->where('vendor_id', $vendor->id)->update(['auth_token' => null]);
            if (Schema::hasColumn('vendor_employees', 'login_remember_token')) {
                VendorEmployee::withoutGlobalScopes()->where('vendor_id', $vendor->id)->update(['login_remember_token' => bin2hex(random_bytes(30))]);
            }
            DB::table('vendor_security_tokens')->where('id', $locked->id)->update(['consumed_at' => now(), 'updated_at' => now()]);
            DB::table('vendor_security_tokens')->where('vendor_id', $vendor->id)->whereNull('consumed_at')
                ->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);

            return true;
        });
    }

    public function revokePurpose(int $vendorId, string $purpose): void
    {
        DB::table('vendor_security_tokens')->where('vendor_id', $vendorId)->where('purpose', $purpose)
            ->whereNull('consumed_at')->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
    }

    public function matchesSubject(object $record, Vendor $vendor): bool
    {
        return hash_equals($record->subject_hash, $this->subjectHash($vendor));
    }

    private function subjectHash(Vendor $vendor): string
    {
        $identity = $vendor->id.'|'.strtolower(trim((string) $vendor->getRawOriginal('email'))).'|'
            .VendorSelfRegistrationInput::normalizePhone((string) $vendor->getRawOriginal('phone')).'|'.$vendor->getRawOriginal('created_at');

        return hash_hmac('sha256', $identity, (string) config('app.key'));
    }

    private function digest(string $raw, string $purpose, ?int $vendorId): string
    {
        $key = (string) config('app.key');
        if (strlen($key) < 32) {
            throw new \LogicException('Vendor security tokens require a configured application key.');
        }

        return hash_hmac('sha256', $purpose.'|'.($purpose === self::API_RESET ? $vendorId.'|' : '').$raw, $key);
    }
}
