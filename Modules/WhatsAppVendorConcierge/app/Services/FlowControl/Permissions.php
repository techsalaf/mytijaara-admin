<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\FlowControl;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Permissions
{
    public const ALL = ['view', 'drafts', 'validate', 'publish', 'rollout', 'test_send', 'applications', 'recovery', 'diagnostics', 'policies', 'settings', 'emergency'];

    public function allows(object $admin, string $permission): bool
    {
        if (! in_array($permission, self::ALL, true)) {
            return false;
        }
        if ((int) $admin->role_id === 1) {
            return true;
        }
        if (! Schema::hasTable('admin_roles')) {
            return false;
        }
        $role = DB::table('admin_roles')->where('id', $admin->role_id)->first(['modules', 'status']);
        $modules = $role ? json_decode($role->modules ?? '[]', true) : [];
        if (! $role || ! $role->status || ! is_array($modules) || ! in_array('store', $modules, true) || ! in_array('contact_messages', $modules, true)) {
            return false;
        }

        return Schema::hasTable('wa_flow_control_permissions') && DB::table('wa_flow_control_permissions')->where('role_id', $admin->role_id)->where('permission', $permission)->exists();
    }

    public function assert(string $permission): void
    {
        $admin = auth('admin')->user();
        abort_unless($admin && $this->allows($admin, $permission), 403, 'This Flow operation requires an additional permission.');
    }
}
