<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissionId = DB::table('permissions')
            ->where('name', 'enquiry.create')
            ->value('id');

        if (!$permissionId) {
            // Fresh installations receive this permission through RolePermissionSeeder.
            return;
        }

        $roleIds = DB::table('roles')
            ->whereIn('name', ['agent', 'staff'])
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            DB::table('permission_role')->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id' => $roleId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Keep grants intact on rollback to avoid removing a permission that
        // may also have been explicitly granted by an administrator.
    }
};
