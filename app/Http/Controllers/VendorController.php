<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\Module;
use Illuminate\Http\Request;
use App\CentralLogics\Helpers;
use App\Models\BusinessSetting;
use Illuminate\Http\JsonResponse;
use App\Models\SubscriptionPackage;
use Gregwar\Captcha\CaptchaBuilder;
use App\Models\ModuleZone;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class VendorController extends Controller
{
    public function create()
    {
        $status = Helpers::get_business_settings ('toggle_store_registration');
        if(!isset($status) || $status == '0')
        {
            Toastr::error(translate('messages.not_found'));
            return back();
        }
        $admin_commission= Helpers::get_business_settings ('admin_commission');
        $business_name= Helpers::get_business_settings ('business_name');
        $packages= SubscriptionPackage::where('status',1)->where('module_type', 'all')->latest()->get();
        $custome_recaptcha = new CaptchaBuilder;
        $custome_recaptcha->build();
        Session::put('six_captcha', $custome_recaptcha->getPhrase());

        return view('vendor-views.auth.general-info', compact('custome_recaptcha','admin_commission','business_name','packages' ));
    }

    public function store(Request $request)
    {
        $validator = Validator::make([], []);
        $status = Helpers::get_business_settings('toggle_store_registration');
        if (! isset($status) || $status == '0') {
            $validator->getMessageBag()->add('latitude', translate('messages.not_found'));

            return response()->json(['errors' => Helpers::error_processor($validator)]);

        }

        $recaptcha = Helpers::get_business_settings('recaptcha');

        if (isset($recaptcha) && $recaptcha['status'] == 1) {
            $request->validate([
                'g-recaptcha-response' => [
                    function ($attribute, $value, $fail) use ($recaptcha) {
                        $secret_key = $recaptcha['secret_key'];
                        $gResponse = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
                            'secret' => $secret_key,
                            'response' => $value,
                            'remoteip' => \request()->ip(),
                        ]);

                        if (! $gResponse->successful()) {
                            $fail(translate('ReCaptcha Failed'));
                        }
                    },
                ],
            ]);
        } elseif (strtolower(session('six_captcha')) != strtolower($request->custome_recaptcha)) {
            $validator->getMessageBag()->add('ReCAPTCHA', translate('ReCAPTCHA Failed'));

            return response()->json(['errors' => Helpers::error_processor($validator)]);
        }

        $validator = Validator::make($request->all(), [
            'f_name' => 'required|string|max:100',
            'l_name' => 'required|string|max:100',
            'lang' => 'required|array|min:1',
            'lang.*' => 'required|string|distinct',
            'name' => 'required|array',
            'name.*' => 'nullable|string|max:250',
            'address' => 'required|array',
            'address.*' => 'nullable|string',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'email' => 'required|email|max:191|unique:vendors',
            'phone' => 'required|regex:/^([0-9\s\-\+\(\)]*)$/|min:10|unique:vendors',
            'minimum_delivery_time' => 'required|numeric|min:0',
            'maximum_delivery_time' => 'required|numeric|gte:minimum_delivery_time',
            'password' => ['required', Password::min(8)->mixedCase()->letters()->numbers()->symbols()],
            'zone_id' => 'required',
            'module_id' => 'required',
            'logo' => 'required|image|max:2048|mimes:'.IMAGE_FORMAT_FOR_VALIDATION,
            'cover_photo' => 'required|image|max:2048|mimes:'.IMAGE_FORMAT_FOR_VALIDATION,
            'delivery_time_type' => 'required|in:min,hours,days',
            'business_plan' => 'required|in:commission-base,subscription-base',
            'pickup_zone_id' => 'nullable|array',
            'terms_accepted' => 'required|accepted',
            'privacy_accepted' => 'required|accepted',
        ], [
            'password.min_length' => translate('The password must be at least :min characters long'),
            'password.mixed' => translate('The password must contain both uppercase and lowercase letters'),
            'password.letters' => translate('The password must contain letters'),
            'password.numbers' => translate('The password must contain numbers'),
            'password.symbols' => translate('The password must contain symbols'),
            'password.uncompromised' => translate('The password is compromised. Please choose a different one'),
            'password.custom' => translate('The password cannot contain white spaces.'),
        ]);
        $validator->after(function ($validator) use ($request) {
            if (! is_array($request->lang) || ! is_array($request->name) || ! is_array($request->address)) {
                return;
            }
            if (! in_array('default', $request->lang, true)
                || count($request->lang) !== count($request->name)
                || count($request->lang) !== count($request->address)) {
                $validator->errors()->add('lang', 'Provide matching names and addresses including the default language.');
            }
        });
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)]);
        }
        try {
            $names = array_combine($request->lang, $request->name);
            $addresses = array_combine($request->lang, $request->address);
            $result = app(\App\Services\VendorSelfRegistrationService::class)->register(
                new \App\DTOs\VendorSelfRegistrationInput(
                    firstName: $request->f_name, lastName: $request->l_name,
                    email: $request->email, phone: $request->phone,
                    names: $names, addresses: $addresses,
                    latitude: $request->latitude, longitude: $request->longitude,
                    zoneId: (int) $request->zone_id, moduleId: (int) $request->module_id,
                    minimumDeliveryTime: (string) $request->minimum_delivery_time,
                    maximumDeliveryTime: (string) $request->maximum_delivery_time,
                    deliveryTimeUnit: $request->delivery_time_type,
                    businessPlan: $request->business_plan,
                    packageId: $request->filled('package_id') ? (int) $request->package_id : null,
                    logo: $request->file('logo'), cover: $request->file('cover_photo'),
                    passwordHash: bcrypt($request->password),
                    termsAccepted: $request->boolean('terms_accepted'),
                    privacyAccepted: $request->boolean('privacy_accepted'), source: 'web',
                    pickupZoneIds: $request->input('pickup_zone_id', []),
                    tin: $request->tin, tinExpireDate: $request->tin_expire_date,
                    tinCertificate: $request->file('tin_certificate_image'),
                    policyEvidence: app(\App\Services\RegistrationPolicyService::class)->evidence($request->only(['policy_locale', 'terms_version', 'privacy_version', 'presentation_hash'])),
                )
            );
        } catch (\Illuminate\Validation\ValidationException $error) {
            foreach ($error->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $validator->getMessageBag()->add($field, $message);
                }
            }

            return response()->json(['errors' => Helpers::error_processor($validator)]);
        }

        if ($request->hasSession()) {
            $request->session()->put('vendor_registration_store_id', $result->store->id);
            $request->session()->put('vendor_registration_expires_at', now()->addMinutes(15)->timestamp);
        }

        return response()->json(['redirect_url' => route('restaurant.secondStep', [
            'store_id' => $result->store->id, 'business_plan' => $request->business_plan,
        ])]);
    }

    public function get_all_modules(Request $request){
        $module_data = Module::Active()->whereHas('zones', function($query)use ($request){
            $query->where('zone_id', $request->zone_id);
        })->notParcel()->notRideShare()
        ->where('modules.module_name', 'like', '%'.$request->q.'%')
        ->limit(8)->get()->map(function($module) {
            return [
                'id' => $module->id,
                'text' => $module->module_name
            ];
        });
        return response()->json($module_data);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function get_modules_type(Request $request): JsonResponse
    {
        $module = Module::find($request->id);
        $packages=null;


        if ($module) {
            $packages= SubscriptionPackage::where('status',1)->where('module_type', Helpers::subscriptionPackageType($module))->latest()->get();

            $module = $module->module_type;
            return response()->json([
                'module_type' => $module,
                'view' => view('vendor-views.auth._package_data', compact('packages','module'))->render(),
            ]);
        }

        return response()->json(['module_type' => '','module_zone' => false]);
    }


    public function check_module_type(Request $request): JsonResponse
    {
        $module = Module::find($request->id);
        $moduleZone= null;
        if ($module) {
            if($request->zone_id){
            $moduleZone=  ModuleZone::where('module_id', $module->id)->where('zone_id', $request->zone_id)->exists();
            }

        }
        return response()->json(['module_zone' => $moduleZone]);
    }


    public function business_plan(Request $request){
        $store=Store::find($request->store_id);

        if ($request->business_plan == 'subscription-base' && $request->package_id != null ) {
            $key=['subscription_free_trial_days','subscription_free_trial_type','subscription_free_trial_status'];
            $free_trial_settings=BusinessSetting::whereIn('key', $key)->pluck('value','key');

            return view('vendor-views.auth.register-subscription-payment',[
            'package_id'=> $request->package_id,
            'store_id' => $request->store_id,
            'free_trial_settings'=>$free_trial_settings,
            'payment_methods' => Helpers::getActivePaymentGateways(),

            ]);
        }
        elseif($request->business_plan == 'commission-base' ){
            $store->store_business_model = 'commission';
            $store->save();
            return view('vendor-views.auth.register-complete',[
                'type'=>'commission'
            ]);
        }
        else{
            $admin_commission= BusinessSetting::where('key','admin_commission')->first();
            $business_name= BusinessSetting::where('key','business_name')->first();
            $packages= SubscriptionPackage::where('status',1)->where('module_type', Helpers::subscriptionPackageType($store))->get();
            Toastr::error(translate('messages.please_follow_the_steps_properly.'));
            return view('vendor-views.auth.register-step-2',[
                'admin_commission'=> $admin_commission?->value,
                'business_name'=> $business_name?->value,
                'packages'=> $packages,
                'store_id' => $request->store_id,
                'type'=>$request->type
                ]);
        }

    }

    public function secondStep(Request $request){
        $store=Store::findOrFail($request->store_id);
        if ($request->business_plan == 'subscription-base' && $store->package_id != null ) {

            $key=['subscription_free_trial_days','subscription_free_trial_type','subscription_free_trial_status'];
            $free_trial_settings=BusinessSetting::whereIn('key', $key)->pluck('value','key');

            return view('vendor-views.auth.register-subscription-payment',[
            'package_id'=> $store->package_id,
            'store_id' => $store->id,
            'free_trial_settings'=>$free_trial_settings,
            'payment_methods' => Helpers::getActivePaymentGateways(),

            ]);
        }
        elseif($request->business_plan == 'commission-base' ){
            $store->store_business_model = 'commission';
            $store->save();
            return view('vendor-views.auth.register-complete',[
                'type'=>'commission'
            ]);
        }
        else{
            $admin_commission= BusinessSetting::where('key','admin_commission')->first();
            $business_name= BusinessSetting::where('key','business_name')->first();
            $packages= SubscriptionPackage::where('status',1)->where('module_type', Helpers::subscriptionPackageType($store))->get();
            return view('vendor-views.auth.register-step-2',[
                'admin_commission'=> $admin_commission?->value,
                'business_name'=> $business_name?->value,
                'packages'=> $packages,
                'store_id' => $store->id,
                'type'=>$request->type
                ]);
        }

    }

    public function payment(Request $request){
        $request->validate([
            'package_id' => 'required',
            'store_id' => 'required',
            'payment' => 'required'
        ]);

        $store= Store::Where('id',$request->store_id)->first();
        $package = SubscriptionPackage::withoutGlobalScope('translate')->find($request->package_id);
        abort_unless($store && $package && (int) $package->status === 1 && $package->module_type === Helpers::subscriptionPackageType($store->module), 403);

        if(!in_array($request->payment,['free_trial'])){
            $url= route('restaurant.final_step',['store_id' => $store->id?? null]);
            return redirect()->away(Helpers::subscriptionPayment(store_id:$store->id,package_id:$package->id,payment_gateway:$request->payment,payment_platform:'web',url:$url,type: 'new_join'));
        }
        if ($request->payment == 'free_trial' && BusinessSetting::where('key', 'subscription_free_trial_status')->value('value') != '1') abort(403);
        if($request->payment == 'free_trial'){
            $plan_data=   Helpers::subscription_plan_chosen(store_id:$store->id,package_id:$package->id,payment_method:'free_trial',discount:0,reference:'free_trial',type: 'new_join');
        }
        $plan_data != false ?  Toastr::success( translate('Successfully_Subscribed.')) : Toastr::error( translate('Something_went_wrong!.'));
        return to_route('restaurant.final_step');
    }

public function back(Request $request){
    $admin_commission= BusinessSetting::where('key','admin_commission')->first();
    $business_name= BusinessSetting::where('key','business_name')->first();
    $store=Store::where('id',$request->store_id)->with('module')->first();
    $module=$store?->module?->module_type ?? 'all';
    $packages= SubscriptionPackage::where('status',1)->where('module_type', Helpers::subscriptionPackageType($store))->get();
    return view('vendor-views.auth.register-step-2',[
        'admin_commission'=> $admin_commission?->value,
        'business_name'=> $business_name?->value,
        'packages'=> $packages,
        'store_id' => $request->store_id,
        'module' => $module
        ]);
}


public function final_step(Request $request){


    $store_id= null;
    $payment_status= null;
    if($request?->store_id && is_string($request?->store_id)){
        $data = explode('?', $request?->store_id);
        $store_id = $data[0];
        $payment_status = $data[1]  != 'flag=success' ? 'fail': 'success';
    }

    return view('vendor-views.auth.register-complete',['store_id' =>$store_id,'payment_status'=> $payment_status]);
}

}
