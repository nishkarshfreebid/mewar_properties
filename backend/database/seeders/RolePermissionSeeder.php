<?php
namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $map = [
            'property.view_published' => 'View published properties',
            'property.create' => 'Create properties',
            'property.manage_own' => 'Manage owned properties',
            'property.update_assigned' => 'Update assigned properties',
            'property.approve' => 'Approve properties',
            'property.publish' => 'Publish properties',
            'property.assign_agent' => 'Assign property agents',
            'enquiry.create' => 'Create enquiries',
            'enquiry.view_own' => 'View own enquiries',
            'enquiry.view_assigned' => 'View assigned enquiries',
            'enquiry.manage_assigned' => 'Manage assigned enquiries',
            'enquiry.view_own_property' => 'View enquiries on owned properties',
            'visit.create' => 'Request property visits',
            'visit.view_own' => 'View own visits',
            'visit.manage_assigned' => 'Manage assigned visits',
            'visit.view_own_property' => 'View visits on owned properties',
            'payment.create' => 'Initiate payments',
            'payment.view_own' => 'View own payments',
            'payment.view_property' => 'View assigned-property payments',
            'payment.view_own_property' => 'View owned-property payments',
            'payment.manage' => 'Manage payments',
            'user.manage_roles' => 'Manage user roles',
            'audit.view' => 'View audit logs',
        ];

        $roleMap = [
            'admin' => array_keys($map),
            'agent' => [
                'property.view_published', 'property.update_assigned',
                'enquiry.view_assigned', 'enquiry.manage_assigned',
                'visit.manage_assigned', 'payment.view_property',
            ],
            'customer' => [
                'property.view_published', 'enquiry.create', 'enquiry.view_own',
                'visit.create', 'visit.view_own', 'payment.create', 'payment.view_own',
            ],
            'owner' => [
                'property.view_published', 'property.create', 'property.manage_own',
                'enquiry.view_own_property', 'visit.view_own_property',
                'payment.view_own_property',
            ],
            'staff' => [
                'property.view_published', 'enquiry.view_assigned',
                'enquiry.manage_assigned', 'visit.manage_assigned',
            ],
        ];

        $labels = [
            'admin' => 'Administrator',
            'agent' => 'Property Agent',
            'customer' => 'Customer',
            'owner' => 'Property Owner',
            'staff' => 'Staff',
        ];

        DB::transaction(function () use ($map, $roleMap, $labels) {
            $ids = [];

            foreach ($map as $name => $label) {
                $ids[$name] = Permission::updateOrCreate(
                    ['name' => $name],
                    ['display_name' => $label]
                )->id;
            }

            foreach ($roleMap as $name => $permissions) {
                $role = Role::updateOrCreate(
                    ['name' => $name],
                    ['display_name' => $labels[$name]]
                );

                $role->permissions()->sync(array_map(
                    fn ($permission) => $ids[$permission],
                    $permissions
                ));
            }
        });
    }
}
