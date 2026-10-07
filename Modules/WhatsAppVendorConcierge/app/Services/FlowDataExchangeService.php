<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\WhatsAppVendorConcierge\app\Models\VendorFlowSession;

class FlowDataExchangeService
{
    public const SCREENS = ['OWNER', 'STORE', 'LOCATION_DELIVERY', 'PLAN_LOGO', 'COVER', 'TIN', 'REVIEW'];

    public const FIELDS = [
        'OWNER' => ['first_name', 'surname', 'email'], 'STORE' => ['store_name', 'module_id', 'address'],
        'LOCATION_DELIVERY' => ['latitude', 'longitude', 'zone_id', 'minimum_delivery_time', 'maximum_delivery_time', 'delivery_time_unit', 'pickup_zone_ids'],
        'PLAN_LOGO' => ['business_plan', 'package_id'], 'COVER' => [], 'TIN' => ['tin', 'tin_expire_date'],
        'REVIEW' => ['terms_agreed', 'privacy_acknowledged', 'terms_version', 'privacy_version', 'presentation_hash'],
    ];

    public function handle(array $request): array
    {
        if (($request['action'] ?? '') === 'ping') {
            return ['data' => ['status' => 'active']];
        }
        if (! config('whatsapp-vendor-flow.enabled')) {
            throw new \RuntimeException('Flow disabled.');
        }
        $token = $request['flow_token'] ?? '';
        if (! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new \InvalidArgumentException('Invalid Flow session.');
        }

        return DB::transaction(function () use ($request, $token) {
            $s = VendorFlowSession::where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if (! $s || $s->consumed_at || $s->expires_at->isPast() || $s->definition_version !== config('whatsapp-vendor-flow.definition_version') || $s->flow_id !== config('whatsapp-vendor-flow.flow_id')) {
                throw new \InvalidArgumentException('Flow session expired or unavailable.');
            }
            $sm = app(FlowStateMachine::class);
            $host = \Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession::find($s->onboarding_session_id);
            if (! $host || in_array($host->status, ['abandoned', 'expired'], true)) {
                throw new \InvalidArgumentException('Flow host session is no longer active.');
            }
            $action = $request['action'] ?? '';
            if ($s->vendor_id || $s->state === 'flow_submitted') {
                return ['screen' => 'SUCCESS', 'data' => ['extension_message_response' => ['params' => ['flow_token' => $token, 'flow_id' => $s->flow_id, 'definition_version' => $s->definition_version, 'submitted' => true]]]];
            }
            if (isset($request['data']['error'])) {
                return ['data' => ['acknowledged' => true]];
            }
            if ($action === 'INIT') {
                if ($s->state === 'flow_offered') {
                    $sm->transition($s, 'flow_opened');
                }
                $sm->event($s, 'flow_opened');

                return $this->response($s, $s->screen);
            }
            $screen = $request['screen'] ?? $s->screen;
            if (! in_array($screen, self::SCREENS, true)) {
                throw new \InvalidArgumentException('Invalid Flow screen.');
            }
            if ($action === 'BACK') {
                if (! isset($request['screen']) || $screen === $s->screen) {
                    $screen = self::SCREENS[max(0, array_search($s->screen, self::SCREENS, true) - 1)];
                } $s->update(['screen' => $screen]);
                if ($s->state === 'flow_submitted') {
                    $sm->transition($s, 'flow_draft');
                }

                return $this->response($s, $screen);
            }
            if ($action !== 'data_exchange' || array_search($screen, self::SCREENS, true) > array_search($s->screen, self::SCREENS, true)) {
                throw new \InvalidArgumentException('Invalid Flow navigation.');
            }
            if ($s->state === 'flow_submitted') {
                $sm->transition($s, 'flow_draft');
            }
            if ($s->state === 'flow_opened') {
                $sm->transition($s, 'flow_draft');
            }
            $values = $request['data'] ?? [];
            if (! is_array($values)) {
                throw new \InvalidArgumentException('Invalid screen payload.');
            }
            $draft = array_replace($s->draft ?? [], array_intersect_key(app(FlowFieldMapper::class)->allowlist($values), array_flip(self::FIELDS[$screen])));
            try {
                if ($screen === 'OWNER') {
                    validator($draft, ['first_name' => 'required|string|max:100', 'surname' => 'required|string|max:100', 'email' => 'required|email|max:100'])->validate();
                }
                if ($screen === 'STORE') {
                    validator($draft, ['store_name' => 'required|string|max:250', 'address' => 'required|string|max:500', 'module_id' => 'required|integer|exists:modules,id,status,1'])->validate();
                }
                if ($screen === 'LOCATION_DELIVERY') {
                    validator($draft, ['latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180', 'zone_id' => 'required|integer|exists:zones,id,status,1', 'minimum_delivery_time' => 'required|numeric|min:0', 'maximum_delivery_time' => 'required|numeric|gte:minimum_delivery_time', 'delivery_time_unit' => 'required|in:min,hours,days'])->validate();
                }
                $s->draft = $draft;
                $s->save();
                if (in_array($s->state, ['correction_required', 'failed_recoverable'], true)) {
                    $sm->transition($s, 'flow_draft');
                }
                if ($screen === 'TIN' && empty($values['media'])) {
                    DB::table('wa_vendor_flow_media')->where('flow_session_id', $s->id)->where('role', 'tin_document')->where('state', 'staged')->update(['state' => 'superseded', 'updated_at' => now()]);
                }
                $role = ['PLAN_LOGO' => 'logo', 'COVER' => 'cover', 'TIN' => 'tin_document'][$screen] ?? null;
                if ($role && ($role !== 'tin_document' || ! empty($values['media']))) {
                    $sm->transition($s, 'media_processing');
                    app(FlowMediaService::class)->stage($s, $role, $values['media'] ?? []);
                    $sm->transition($s, 'flow_draft');
                }
                if ($screen === 'REVIEW') {
                    foreach (self::FIELDS['REVIEW'] as $field) {
                        if (! array_key_exists($field, $values)) {
                            throw ValidationException::withMessages(['agreement' => 'Review and explicitly confirm both policy controls.']);
                        }
                    }
                    if (($values['terms_agreed'] ?? null) !== true || ($values['privacy_acknowledged'] ?? null) !== true) {
                        throw ValidationException::withMessages(['agreement' => 'Explicit agreement and acknowledgment are required.']);
                    }
                    if (! is_string($values['review_hash'] ?? null) || ! hash_equals($this->reviewHash($s, $draft), $values['review_hash'])) {
                        throw ValidationException::withMessages(['review' => 'Details changed. Review the latest summary and agree again.']);
                    }
                    $input = app(FlowFieldMapper::class)->input($s, $draft);
                    app(\App\Services\RegistrationPolicyService::class)->verify($input->policyEvidence);
                    $m = $s->policy_manifest;
                    if ($input->policyEvidence->presentationHash !== $m['presentation_hash']) {
                        throw ValidationException::withMessages(['policy' => 'Review the current policy versions again.']);
                    }
                    app(\App\Services\VendorSelfRegistrationService::class)->validatePreparedInput($input);
                    $sm->transition($s, 'flow_submitted');

                    return ['screen' => 'SUCCESS', 'data' => ['extension_message_response' => ['params' => ['flow_token' => $token, 'flow_id' => $s->flow_id, 'definition_version' => $s->definition_version, 'submitted' => true]]]];
                }
                $next = self::SCREENS[array_search($screen, self::SCREENS, true) + 1];
                $s->update(['screen' => $next]);
                $sm->event($s, 'draft_progress', $screen);

                return $this->response($s, $next);
            } catch (ValidationException|\InvalidArgumentException $e) {
                $s->update(['draft' => $draft, 'error_code' => 'validation_correction']);
                $sm->transition($s, 'correction_required');
                $sm->event($s, 'correction_required', $screen);
                $out = $this->response($s, $screen);
                $out['data']['error_message'] = 'Please check this screen. Review earlier details using Back if needed.';

                return $out;
            } catch (\Throwable $e) {
                $sm->transition($s, 'failed_recoverable');
                $s->update(['error_code' => 'media_or_endpoint_failure']);
                $sm->event($s, 'media_failure', $screen);
                $out = $this->response($s, $screen);
                $out['data']['error_message'] = 'Temporary problem. Please try this step again.';

                return $out;
            }
        }, 3);
    }

    public function response(VendorFlowSession $s, string $screen): array
    {
        $d = $s->draft ?? [];
        $o = app(FlowOptions::class);
        $data = [];
        foreach (self::FIELDS[$screen] as $field) {
            $data[$field] = $d[$field] ?? (in_array($field, ['terms_agreed', 'privacy_acknowledged'], true) ? false : ($field === 'pickup_zone_ids' ? [] : ''));
        }
        if ($screen === 'STORE') {
            $data['modules'] = $o->modules();
        }
        if ($screen === 'LOCATION_DELIVERY') {
            $data['zones'] = $o->zones((int) ($d['module_id'] ?? 0));
            $r = \App\Models\Module::find($d['module_id'] ?? 0)?->module_type === 'rental' && addon_published_status('Rental');
            $data['is_rental'] = $r;
            $data['pickup_zones'] = $r ? $o->pickups() : [];
        }
        if ($screen === 'PLAN_LOGO') {
            $data['plans'] = $o->plans();
            $data['show_plan'] = count($data['plans']) > 1;
            $data['business_plan'] = $d['business_plan'] ?? ($data['plans'][0]['id'] ?? '');
            $data['show_package'] = $data['business_plan'] === 'subscription-base';
            $data['packages'] = $o->packages((int) ($d['module_id'] ?? 0));
            if (! $data['packages']) {
                $data['plans'] = array_values(array_filter($data['plans'], fn ($plan) => $plan['id'] !== 'subscription-base'));
            }
            if (! $data['plans']) {
                throw new \RuntimeException('No eligible registration plan is available.');
            }
            if (! in_array($data['business_plan'], array_column($data['plans'], 'id'), true)) {
                $data['business_plan'] = $data['plans'][0]['id'];
            }
            $data['show_plan'] = count($data['plans']) > 1;
            $data['show_package'] = $data['business_plan'] === 'subscription-base';
        }
        if ($screen === 'REVIEW') {
            $data['review_hash'] = $this->reviewHash($s, $d);
            $m = $s->policy_manifest;
            $data['terms_url'] = $m['terms']['url'];
            $data['privacy_url'] = $m['privacy']['url'];
            $data['terms_version'] = $m['terms']['version'];
            $data['privacy_version'] = $m['privacy']['version'];
            $data['presentation_hash'] = $m['presentation_hash'];
            $data['summary'] = 'Owner: '.($d['first_name'] ?? '').' '.($d['surname'] ?? '')."\nStore: ".($d['store_name'] ?? '')."\nAddress: ".($d['address'] ?? '')."\nDelivery: ".($d['minimum_delivery_time'] ?? '').'-'.($d['maximum_delivery_time'] ?? '').' '.($d['delivery_time_unit'] ?? '')."\nReview earlier screens using Back. Approval is required before account access.";
            $module = \App\Models\Module::find($d['module_id'] ?? 0);
            $zone = \App\Models\Zone::find($d['zone_id'] ?? 0);
            $data['summary'] .= "\nEmail: ".($d['email'] ?? '')."\nBusiness: ".($module?->module_name ?? '')."\nZone: ".($zone?->name ?? '')."\nCoordinates: ".($d['latitude'] ?? '').', '.($d['longitude'] ?? '')."\nPlan: ".($d['business_plan'] ?? '')."\nLogo and cover: provided";
            if (($d['business_plan'] ?? null) === 'subscription-base' && ! empty($d['package_id'])) {
                $data['summary'] .= "\nPackage: ".(\App\Models\SubscriptionPackage::find($d['package_id'])?->package_name ?? '');
            }
            if (! empty($d['pickup_zone_ids'])) {
                $data['summary'] .= "\nPickup zones: ".implode(', ', \App\Models\Zone::whereIn('id', $d['pickup_zone_ids'])->pluck('name')->all());
            }
            if (! empty($d['tin'])) {
                $data['summary'] .= "\nTIN information: provided";
            }
            $data['policy_versions'] = 'Locale: '.$s->locale.' | Terms: '.$data['terms_version'].' | Privacy: '.$data['privacy_version'];
        }
        foreach (['modules' => 'module_id', 'zones' => 'zone_id', 'packages' => 'package_id'] as $key => $field) {
            if (isset($data[$key]) && isset($data[$field]) && ! in_array((string) $data[$field], array_column($data[$key], 'id'), true)) {
                $data[$field] = '';
            }
        }
        if (isset($data['pickup_zones'],$data['pickup_zone_ids'])) {
            $data['pickup_zone_ids'] = array_values(array_intersect(array_map('strval', $data['pickup_zone_ids']), array_column($data['pickup_zones'], 'id')));
        }
        foreach (['modules', 'zones', 'pickup_zones', 'packages'] as $key) {
            if (array_key_exists($key, $data) && ! $data[$key]) {
                $data[$key] = [['id' => '__unavailable__', 'title' => 'No eligible options', 'enabled' => false]];
            }
        }

        return ['screen' => $screen, 'data' => $data];
    }

    private function reviewHash(VendorFlowSession $s, array $draft): string
    {
        $fields = array_diff_key(app(FlowFieldMapper::class)->allowlist($draft), array_flip(self::FIELDS['REVIEW']));
        ksort($fields);
        $media = DB::table('wa_vendor_flow_media')->where('flow_session_id', $s->id)->where('state', 'staged')->orderBy('role')->select('role', 'media_identity', 'sha256')->get()->toArray();

        return hash_hmac('sha256', json_encode([$s->id, $s->token_hash, $s->policy_manifest['presentation_hash'], $fields, $media], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
