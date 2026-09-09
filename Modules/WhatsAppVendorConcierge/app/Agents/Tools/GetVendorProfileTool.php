<?php

namespace Modules\WhatsAppVendorConcierge\app\Agents\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class GetVendorProfileTool extends BaseVendorTool
{
    public function description(): string
    {
        return 'Get the vendor\'s profile and business information including company details, contact info, and verification status.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        $this->recordTool('GetVendorProfileTool');

        $vendor = $this->requireVendor();
        $store = $this->store;

        $profile = [
            'vendor_id' => $vendor->id,
            'name' => trim(($vendor->f_name ?? '') . ' ' . ($vendor->l_name ?? '')),
            'phone' => $vendor->phone,
            'email' => $vendor->email,
            'status' => $vendor->status ? 'Active' : 'Inactive',
            'created_at' => $vendor->created_at?->format('M d, Y'),
        ];

        if ($vendor->userinfo) {
            $info = $vendor->userinfo;
            $profile['company_name'] = $info->company_name;
            $profile['business_address'] = $info->address;
            $profile['city'] = $info->city;
            $profile['state'] = $info->state;
            $profile['business_type'] = $info->business_type;
            $profile['registration_number'] = $info->registration_number;
            $profile['tax_id'] = $info->tax_id;
        }

        if ($store) {
            $profile['store'] = [
                'id' => $store->id,
                'name' => $store->name,
                'phone' => $store->phone,
                'email' => $store->email,
                'address' => $store->address,
                'latitude' => $store->latitude,
                'longitude' => $store->longitude,
                'status' => $store->status ? 'Approved' : 'Pending',
                'active' => $store->active,
                'module' => $store->module?->name,
                'zone' => $store->zone?->name,
                'delivery_enabled' => $store->delivery,
                'takeaway_enabled' => $store->take_away,
                'off_day' => $store->off_day,
            ];
        }

        $this->context->setVendorProfile($profile);

        // Build human-readable response
        $lines = [
            "**Your Vendor Profile**",
            "",
            "👤 **Name:** {$profile['name']}",
            "📞 **Phone:** {$profile['phone']}",
            "📧 **Email:** {$profile['email']}",
            "📊 **Status:** {$profile['status']}",
            "📅 **Joined:** {$profile['created_at']}",
        ];

        if (!empty($profile['company_name'])) {
            $lines[] = "🏢 **Business:** {$profile['company_name']}";
        }
        if (!empty($profile['business_address'])) {
            $lines[] = "📍 **Address:** {$profile['business_address']}" . ($profile['city'] ? ", {$profile['city']}" : "") . ($profile['state'] ? ", {$profile['state']}" : "");
        }

        if ($store) {
            $lines[] = "";
            $lines[] = "**Your Shop ({$store->name})**";
            $lines[] = "📊 **Status:** " . ($store->status ? '✅ Approved' : '⏳ Pending Approval');
            $lines[] = "🏪 **Open/Closed:** " . ($store->active ? '🟢 Open' : '🔴 Closed');
            $lines[] = "🚚 **Delivery:** " . ($store->delivery ? 'Enabled' : 'Disabled');
            $lines[] = "🏃 **Takeaway:** " . ($store->take_away ? 'Enabled' : 'Disabled');
            if ($store->off_day) {
                $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                $off = array_map(fn($d) => $days[(int)$d], str_split($store->off_day));
                $lines[] = "📅 **Closed:** " . implode(', ', $off);
            }
        }

        return implode("\n", $lines);
    }
}