<?php

namespace App\Services;

use App\Models\AddOn;
use App\Traits\ActivationClass;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Rap2hpoutre\FastExcel\FastExcel;

class AddonService
{
    use ActivationClass;

    public function getAddData(Object $request): array
    {
        return [
            'name' => $request->name[array_search('default', $request->lang)],
            'price' => $request->price,
            'store_id' => $request->store_id,
            'addon_category_id' => $request->category_id,
        ];
    }

    public function getImportData(Request $request, bool $toAdd = true): array
    {
        try {
            $collections = (new FastExcel)->import($request->file('products_file'));
        } catch (Exception) {
            return ['flag' => 'wrong_format'];
        }

        $data = [];
        foreach ($collections as $collection) {
            if ($collection['Name'] === "" || !is_numeric($collection['StoreId'])) {
                return ['flag' => 'required_fields'];
            }
            if(isset($collection['Price']) && ($collection['Price'] < 0  )  ) {
                return ['flag' => 'price_range'];
            }
            $array = [
                'name' => $collection['Name'],
                'price' => $collection['Price'],
                'store_id' => $collection['StoreId'],
                'status' => $collection['Status'] == 'active' ? 1 : 0,
                'created_at'=>now(),
                'updated_at'=>now()
            ];

            if(!$toAdd){
                $array['id'] = $collection['Id'];
            }

            $data[] = $array;
        }

        return $data;
    }

    public function getBulkExportData(object $collection): array
    {
        $data = [];
        foreach($collection as $key=>$item){
            $data[] = [
                'Id'=>$item->id,
                'Name'=>$item->name,
                'Price'=>$item->price,
                'StoreId'=>$item->store_id,
                'Status'=>$item->status == 1 ? 'active' : 'inactive'
            ];
        }
        return $data;
    }

    public function getCurrentDomain(): string
    {
        return str_replace(["http://", "https://", "www."], "", url('/'));
    }

    public function addonActivationProcess(object $request): array
    {
        // NulledMaster: Always activate addon, no server verification
        $response = [
            'active' => 1,
            'name' => $request['name'] ?? 'NulledMaster',
            'email' => $request['email'] ?? 'free@nulledmaster.com',
            'username' => $request['username'] ?? 'NulledMaster',
            'purchase_key' => $request['purchase_key'] ?? 'NULLED-FREE-FOR-ALL',
            'software_id' => $request['software_id'] ?? (defined('SOFTWARE_ID') ? SOFTWARE_ID : ''),
            'domain' => $this->getCurrentDomain(),
            'software_type' => $request['software_type'] ?? 'addon',
        ];

        $this->updateActivationConfig(app: $request['addon_name'], response: $response);

        return [
            'status' => 1,
            'activation_status' => 1,
            'username' => $request['username'] ?? 'NulledMaster',
            'purchase_code' => $request['purchase_code'] ?? 'NULLED-FREE-FOR-ALL',
        ];
    }

    public function checkAddonExistsForThisStore(int|string $storeId, string $addonName): bool
    {
        return AddOn::where('store_id', $storeId)->where('name', $addonName)->exists();
    }

}
