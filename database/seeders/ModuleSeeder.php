<?php

namespace Database\Seeders;

use App\Models\Module;
use Illuminate\Database\Seeder;

class ModuleSeeder extends Seeder
{
    /**
     * Milestone 2 (Dynamic Module system, Task #42). Seeds the DB-driven
     * module catalog from `config/modules.available` -- that config array
     * is kept as the DEFAULT DATA source (so this list isn't duplicated a
     * third time), but it is no longer what's read at runtime; the
     * `modules` table is (see HandleInertiaRequests/SettingsController).
     * Not tenant-scoped -- no CurrentTenant dependency, same as
     * PackageSeeder.
     */
    public function run(): void
    {
        $order = 1;

        foreach (config('modules.available', []) as $key => $label) {
            Module::updateOrCreate(
                ['key' => $key],
                ['label' => $label, 'is_core' => false, 'sort_order' => $order++]
            );
        }
    }
}
