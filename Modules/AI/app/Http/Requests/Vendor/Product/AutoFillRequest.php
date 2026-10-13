<?php

namespace Modules\AI\app\Http\Requests\Vendor\Product;

use App\Http\Requests\BaseRequest;

abstract class AutoFillRequest extends BaseRequest
{
    public function usageContext(): array
    {
        $requestType = $this->input('requestType');

        return [
            'store_id' => $this->input('store_id'),
            'request_type' => is_scalar($requestType) ? (string) $requestType : null,
        ];
    }
}
