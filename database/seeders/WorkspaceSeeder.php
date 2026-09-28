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
        /*
         * v2.84.0 -- THE ORDER IS THE PRODUCT LADDER.
         *
         * HSE first, because Starter IS HSE and every plan above it is HSE
         * plus something. The order decides which workspace an account lands
         * in when it has more than one and has chosen no focus, so "People
         * before Safety" was not a cosmetic detail -- it sent a Professional
         * customer to the wrong half of their product on every sign-in.
         *
         * The four sold workspaces come first; the retired ones keep their
         * rows (a catalogue row is not tenant data, and deleting them would
         * orphan history) but no plan grants them any more.
         */
        $workspaces = [
            ['key' => 'hse', 'label' => 'Health, Safety & Environment', 'icon' => 'HardHat', 'tier' => 'department'],
            ['key' => 'hr', 'label' => 'People / HRD', 'icon' => 'Users', 'tier' => 'department'],
            ['key' => 'logistics', 'label' => 'Logistics / Warehouse', 'icon' => 'PackageSearch', 'tier' => 'department'],
            ['key' => 'management', 'label' => 'Management', 'icon' => 'TrendingUp', 'tier' => 'department'],
            ['key' => 'project-management', 'label' => 'Project Management', 'icon' => 'FolderKanban', 'tier' => 'department'],
            ['key' => 'warehouse', 'label' => 'Warehouse', 'icon' => 'Warehouse', 'tier' => 'department'],
            ['key' => 'procurement', 'label' => 'Procurement', 'icon' => 'ShoppingCart', 'tier' => 'department'],
            ['key' => 'asset-management', 'label' => 'Asset Management', 'icon' => 'Box', 'tier' => 'department'],
            ['key' => 'maintenance', 'label' => 'Maintenance', 'icon' => 'Wrench', 'tier' => 'department'],
            ['key' => 'quality-control', 'label' => 'Quality Control', 'icon' => 'BadgeCheck', 'tier' => 'department'],
            ['key' => 'finance', 'label' => 'Finance', 'icon' => 'DollarSign', 'tier' => 'department'],
            ['key' => 'reports', 'label' => 'Reports', 'icon' => 'FileBarChart', 'tier' => 'global'],
            ['key' => 'administration', 'label' => 'Admin Space', 'icon' => 'Settings', 'tier' => 'global', 'is_core' => true],
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
