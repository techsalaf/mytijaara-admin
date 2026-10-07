<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\CentralLogics\Helpers;
use App\DTOs\VendorSelfRegistrationInput;
use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\Vendor;
use App\Models\VendorEmployee;
use App\Services\VendorAuthenticationService;
use App\Services\VendorSelfRegistrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class VendorLoginController extends Controller
{
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email', 'password' => 'required|min:6', 'vendor_type' => 'required|in:owner,employee',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $result = app(VendorAuthenticationService::class)->authenticate(
            $request->email, $request->password, $request->vendor_type, 'api', $request->header('X-Vendor-Auth-Version') === '2'
        );
        if (! $result) {
            return response()->json(['errors' => [['code' => 'auth-001', 'message' => translate('messages.Credential_do_not_match,_please_try_again')]]], 401);
        }
        if (! $result['decision']->eligible) {
            if (isset($result['pre_activation_token'])) {
                return response()->json([
                    'code' => 'subscription_setup_required',
                    'pre_activation' => [
                        'token' => $result['pre_activation_token'], 'token_type' => 'subscription_setup',
                        'expires_in' => 900, 'store_id' => $result['store']->id, 'package_id' => $result['store']->package_id,
                        'module_type' => $result['store']->module?->module_type,
                    ],
                ], 200);
            }

            return response()->json(['errors' => [['code' => $result['decision']->preActivationAllowed ? 'subscription_setup_required' : $result['decision']->reason,
                'message' => translate('Vendor access is unavailable. Please contact support.')]]], 403);
        }
        $data = ['token' => $result['token'], 'zone_wise_topic' => $result['store']->zone?->store_wise_topic,
            'module_type' => $result['store']->module?->module_type];
        if ($request->vendor_type === 'employee') {
            $data['role'] = $result['principal']->role ? json_decode($result['principal']->role->modules) : [];
        }

        return response()->json($data, 200);
    }

    public function logout(Request $request)
    {
        $class = $request->header('vendorType') === 'employee' ? VendorEmployee::class : Vendor::class;
        $class::withoutGlobalScopes()->where('auth_token', $request->bearerToken())->update(['auth_token' => null]);

        return response()->json(['message' => translate('Signed out successfully.')]);
    }

    public function register(Request $request)
    {
        if (BusinessSetting::where('key', 'toggle_store_registration')->value('value') != '1') {
            return response()->json(['errors' => [['code' => 'self-registration', 'message' => translate('messages.store_self_registration_disabled')]]], 403);
        }

        // Keep legacy API spelling at the transport boundary; core accepts canonical values only.
        $subscriptions = (BusinessSetting::where('key', 'subscription_business_model')->value('value') ?? '1') == '1';
        $plan = $subscriptions ? $request->input('business_plan') : 'commission';
        $request->merge([
            'delivery_time_type' => $request->input('delivery_time_type') === 'minute' ? 'min' : $request->input('delivery_time_type'),
            'package_id' => in_array($request->input('package_id'), ['', 'null', null], true) ? null : $request->input('package_id'),
            'tin_expire_date' => $request->input('tin_expire_date') === 'null' ? null : $request->input('tin_expire_date'),
        ]);
        $validator = Validator::make($request->all(), [
            'f_name' => 'required|string|max:100', 'l_name' => 'required|string|max:100',
            'email' => 'required|email|max:100', 'phone' => 'required|string',
            'latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180',
            'zone_id' => 'required|integer', 'module_id' => 'required|integer', 'package_id' => 'nullable|integer',
            'minimum_delivery_time' => 'required|numeric|min:0',
            'maximum_delivery_time' => 'required|numeric|gte:minimum_delivery_time',
            'delivery_time_type' => 'required|in:min,hours,days',
            'password' => ['required', Password::min(8)->mixedCase()->letters()->numbers()->symbols()->uncompromised()],
            'logo' => 'required|image|max:2048|mimes:'.IMAGE_FORMAT_FOR_VALIDATION,
            'cover_photo' => 'required|image|max:2048|mimes:'.IMAGE_FORMAT_FOR_VALIDATION,
            'translations' => 'required|json',
            'terms_accepted' => 'accepted', 'privacy_accepted' => 'accepted',
        ], [
            'password.required' => translate('The password is required'),
            'password.min_length' => translate('The password must be at least :min characters long'),
            'password.mixed' => translate('The password must contain both uppercase and lowercase letters'),
            'password.letters' => translate('The password must contain letters'),
            'password.numbers' => translate('The password must contain numbers'),
            'password.symbols' => translate('The password must contain symbols'),
            'password.uncompromised' => translate('The password is compromised. Please choose a different one'),
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        try {
            [$names, $addresses] = $this->registrationTranslations($request->input('translations'));
            $pickup = $request->input('pickup_zone_id', []);
            if (is_string($pickup)) {
                try {
                    $pickup = json_decode($pickup, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $error) {
                    throw ValidationException::withMessages(['pickup_zone_id' => 'Supply an array of pickup zones.']);
                }
            }
            if (! is_array($pickup) || ! array_is_list($pickup)) {
                throw ValidationException::withMessages(['pickup_zone_id' => 'Supply an array of pickup zones.']);
            }
            $canonicalPlan = match ($plan) {
                'commission' => 'commission-base', 'subscription' => 'subscription-base',
                default => throw ValidationException::withMessages(['business_plan' => 'Select an available business plan.']),
            };
            $result = app(VendorSelfRegistrationService::class)->register(new VendorSelfRegistrationInput(
                firstName: $request->input('f_name'), lastName: $request->input('l_name'),
                email: $request->input('email'), phone: $request->input('phone'),
                names: $names, addresses: $addresses,
                latitude: $request->input('latitude'), longitude: $request->input('longitude'),
                zoneId: (int) $request->input('zone_id'), moduleId: (int) $request->input('module_id'),
                minimumDeliveryTime: (string) $request->input('minimum_delivery_time'),
                maximumDeliveryTime: (string) $request->input('maximum_delivery_time'),
                deliveryTimeUnit: $request->input('delivery_time_type'), businessPlan: $canonicalPlan,
                packageId: $request->input('package_id') === null ? null : (int) $request->input('package_id'),
                logo: $request->file('logo'), cover: $request->file('cover_photo'),
                passwordHash: bcrypt($request->input('password')),
                termsAccepted: $request->boolean('terms_accepted'), privacyAccepted: $request->boolean('privacy_accepted'),
                source: 'api', pickupZoneIds: $pickup, tin: $request->input('tin'),
                tinExpireDate: $request->input('tin_expire_date'), tinCertificate: $request->file('tin_certificate_image'),
                policyEvidence: app(\App\Services\RegistrationPolicyService::class)->evidence($request->only(['policy_locale', 'terms_version', 'privacy_version', 'presentation_hash'])),
            ));
        } catch (ValidationException $error) {
            $errors = [];
            foreach ($error->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $errors[] = ['code' => $field === 'zone' ? 'latitude' : $field, 'message' => $message];
                }
            }

            return response()->json(['errors' => $errors], 403);
        } catch (\Throwable $error) {
            Log::error('API vendor registration failed', ['exception' => $error::class]);

            return response()->json(['errors' => [['code' => 'registration_unavailable', 'message' => 'Registration could not be completed. Please retry.']]], 500);
        }

        $response = ['store_id' => $result->store->id];
        if ($result->subscriptionPaymentRequired) {
            $response['package_id'] = $result->store->package_id;
        }
        $response['type'] = $result->subscriptionPaymentRequired ? 'subscription' : 'commission';
        $response['message'] = translate('messages.application_placed_successfully');

        return response()->json($response, 200);
    }

    /** Resolve by locale/key; client order and row IDs never determine the canonical values. */
    private function registrationTranslations(string $json): array
    {
        $rows = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $validator = Validator::make(['translations' => $rows], [
            'translations' => 'required|array|min:2', 'translations.*' => 'required|array',
            'translations.*.locale' => ['required', 'string', 'max:20', 'regex:/^[a-zA-Z0-9_-]+$/D'],
            'translations.*.key' => 'required|in:name,address', 'translations.*.value' => 'nullable|string',
        ]);
        $validator->validate();
        if (! array_is_list($rows)) {
            throw ValidationException::withMessages(['translations' => 'Supply a list of translated fields.']);
        }
        $values = ['name' => [], 'address' => []];
        foreach ($rows as $row) {
            if (array_key_exists($row['locale'], $values[$row['key']])) {
                throw ValidationException::withMessages(['translations' => 'Supply each locale/key once.']);
            }
            $values[$row['key']][$row['locale']] = $row['value'] ?? null;
        }
        $locale = str_replace('_', '-', config('app.locale', 'en'));
        foreach (['name', 'address'] as $key) {
            $default = $values[$key]['default'] ?? $values[$key][$locale] ?? null;
            if (! is_string($default) || trim($default) === '') {
                throw ValidationException::withMessages(['translations' => 'Supply name and address for the default locale.']);
            }
            $values[$key]['default'] = $default;
        }

        return [$values['name'], $values['address']];
    }
}
