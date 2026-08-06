<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PlatformAdminSeeder extends Seeder
{
    /**
     * Milestone 2 (Tenancy Foundation). Creates the one genuinely new
     * account this milestone introduces: a Platform Super Admin, who
     * works for the platform operator (this app's vendor), not for any
     * customer tenant. Deliberately NOT built by repurposing an existing
     * tenant account -- see the doc comments on
     * 2026_08_14_100043_add_tenant_id_to_users_table and
     * User::isPlatformAdmin() for why every pre-existing account stays a
     * normal tenant user with unchanged behavior.
     *
     * tenant_id is left NULL on purpose -- that is the actual mechanism
     * that makes this a Platform Super Admin (see isPlatformAdmin()), not
     * just a label. CHANGE THIS PASSWORD before deploying to production,
     * same as every other seeded account in UserSeeder.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'platform@ioms.local'],
            [
                'name' => 'Platform Super Admin',
                'password' => Hash::make('password'),
                'role' => User::ROLE_PLATFORM_ADMIN,
                'tenant_id' => null,
                'company_id' => null,
                'department_key' => null,
                'is_active' => true,
            ]
        );
    }
}
