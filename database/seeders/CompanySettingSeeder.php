<?php

namespace Database\Seeders;

use App\Models\CompanySetting;
use Illuminate\Database\Seeder;

class CompanySettingSeeder extends Seeder
{
    /**
     * App-level branding config (shown in sidebar/login/About). Uses
     * updateOrCreate on 'company_name' only if it still holds a previous
     * default (v1: "Shipyard HSE Department", v1.2-1.3.2: "Shipyard
     * Management System") -- an existing install that already customized
     * this value to something else (e.g. a real company name) keeps it
     * untouched. This is the v1.4.0 rebrand to "Integrated Operations
     * Management System" (IOMS).
     */
    public function run(): void
    {
        $current = CompanySetting::where('key', 'company_name')->value('value');
        $previousDefaults = [null, 'Shipyard HSE Department', 'Shipyard Management System'];

        if (in_array($current, $previousDefaults, true)) {
            CompanySetting::updateOrCreate(['key' => 'company_name'], ['value' => 'Integrated Operations Management System']);
        }

        CompanySetting::updateOrCreate(['key' => 'company_subtitle'], ['value' => 'Industrial Operations Platform']);
        CompanySetting::updateOrCreate(['key' => 'company_logo_path'], ['value' => CompanySetting::get('company_logo_path')]);

        // Module toggle registry (v1.5.0): only seeded if it doesn't exist
        // yet, so re-running this seeder never overwrites a Super Admin's
        // module on/off choices. See config/modules.php for the full
        // registry (including future, not-yet-built modules) and
        // Settings > Modules for the toggle UI.
        if (CompanySetting::where('key', 'enabled_modules')->doesntExist()) {
            CompanySetting::create([
                'key' => 'enabled_modules',
                'value' => json_encode(array_keys(config('modules.available'))),
            ]);
        }
    }
}
