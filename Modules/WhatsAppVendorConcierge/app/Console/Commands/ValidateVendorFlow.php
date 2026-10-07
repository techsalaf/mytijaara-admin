<?php

namespace Modules\WhatsAppVendorConcierge\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\WhatsAppVendorConcierge\app\Services\FlowDefinitionValidator;

class ValidateVendorFlow extends Command
{
    protected $signature = 'whatsapp:flow-validate {--fixture= : Local submission fixture path}';

    protected $description = 'Dry-run local Flow definition and allowlisted submission structure checks; no Meta calls.';

    public function handle(FlowDefinitionValidator $validator): int
    {
        try {
            $f = $validator->validate();
            if ($path = $this->option('fixture')) {
                $p = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
                validator($p, ['first_name' => 'required|string', 'surname' => 'required|string', 'email' => 'required|email', 'latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180', 'logo' => 'required', 'cover' => 'required', 'store_name' => 'required|string|max:250', 'address' => 'required|string|max:500', 'module_id' => 'required|integer|min:1', 'zone_id' => 'required|integer|min:1', 'minimum_delivery_time' => 'required|numeric|min:0', 'maximum_delivery_time' => 'required|numeric|gte:minimum_delivery_time', 'delivery_time_unit' => 'required|in:min,hours,days', 'business_plan' => 'required|in:commission-base,subscription-base', 'package_id' => 'required_if:business_plan,subscription-base|nullable|integer|min:1', 'pickup_zone_ids' => 'array', 'pickup_zone_ids.*' => 'integer|min:1', 'terms_version' => 'required|string|max:191', 'privacy_version' => 'required|string|max:191', 'presentation_hash' => 'required|regex:/^[a-f0-9]{64}$/D', 'terms_agreed' => 'accepted', 'privacy_acknowledged' => 'accepted'])->validate();
            }$this->info('Local structural contract valid: '.count($f['screens']).' screens; '.config('whatsapp-vendor-flow.definition_version').'. Remote Meta validation remains required.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Local validation failed: '.$e::class);

            return self::FAILURE;
        }
    }
}
