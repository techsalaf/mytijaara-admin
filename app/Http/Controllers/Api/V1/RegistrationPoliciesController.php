<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;

class RegistrationPoliciesController
{
    public function __invoke(Request $request, \App\Services\RegistrationPolicyService $policies)
    {
        $request->validate(['locale' => 'required|string|max:20']);
        try {
            return response()->json($policies->manifest($request->input('locale')))->header('Cache-Control', 'no-store');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => [['code' => 'policy_unavailable', 'message' => 'Registration policy documents are unavailable.']]], 503);
        }
    }
}
