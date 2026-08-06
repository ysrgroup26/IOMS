<?php

namespace Database\Seeders;

use App\Models\Workspace;
use Illuminate\Database\Seeder;

class WorkspaceSeeder extends Seeder
{
    /**
     * Milestone 2 (Dynamic Workspace system, Task #43). Mirrors
     * resources/js/lib/workspaces.js's WORKSPACES array metadata exactly
     * (key/label/icon-name/tier/is_core/order) so seeding this table
     * changes nothing about today's sidebar until an admin actually edits
     * a row from Settings. Not tenant-scoped -- same catalog-not-tenant-
     * data reasoning as Module/Package.
     */
    public function run(): void
    {
        $workspaces = [
            ['key' => 'hr', 'label' => 'Human Resources', 'icon' => 'Users', 'tier' => 'department'],
            ['key' => 'hse', 'label' => 'HSE', 'icon' => 'HardHat', 'tier' => 'department'],
            ['key' => 'project-management', 'label' => 'Project Management', 'icon' => 'FolderKanban', 'tier' => 'department'],
            ['key' => 'logistics', 'label' => 'Logistics / PPIC', 'icon' => 'PackageSearch', 'tier' => 'department'],
            ['key' => 'warehouse', 'label' => 'Warehouse', 'icon' => 'Warehouse', 'tier' => 'department'],
            ['key' => 'procurement', 'label' => 'Procurement', 'icon' => 'ShoppingCart', 'tier' => 'department'],
            ['key' => 'asset-management', 'label' => 'Asset Management', 'icon' => 'Box', 'tier' => 'department'],
            ['key' => 'maintenance', 'label' => 'Maintenance', 'icon' => 'Wrench', 'tier' => 'department'],
            ['key' => 'quality-control', 'label' => 'Quality Control', 'icon' => 'BadgeCheck', 'tier' => 'department'],
            ['key' => 'finance', 'label' => 'Finance', 'icon' => 'DollarSign', 'tier' => 'department'],
            ['key' => 'reports', 'label' => 'Reports', 'icon' => 'FileBarChart', 'tier' => 'global'],
            ['key' => 'administration', 'label' => 'Administration', 'icon' => 'Settings', 'tier' => 'global', 'is_core' => true],
        ];

        foreach ($workspaces as $order => $workspace) {
            Workspace::updateOrCreate(
                ['key' => $workspace['key']],
                [
                    'label' => $workspace['label'],
                    'icon' => $workspace['icon'],
                    'tier' => $workspace['tier'],
                    'is_core' => $workspace['is_core'] ?? false,
                    'is_active' => true,
                    'sort_order' => $order + 1,
                ]
            );
        }
    }
}
