<?php

namespace Database\Seeders;

use App\Models\CompanySetting;
use App\Models\Module;
use Illuminate\Database\Seeder;

class CompanySettingSeeder extends Seeder
{
    /**
     * App-level branding config (shown in sidebar/login/About). Uses
     * updateOrCreate on 'company_name' only if it still holds a previous
     * default (v1: "Shipyard HSE Department", v1.2-1.3.2: "Shipyard
     * Management System") -- an existing install that already customized
     * this value to something else (e.g. a real company name) keeps it
     * untouched. v2.39.0: now seeds the canonical product name from
     * config("ioms.name") -- "IOMS". The old long-form expansion is a
     * description, never the brand, and is treated as a previous default.
     * v2.40.0: writes go through CompanySetting::set(), which targets the
     * current bucket. This seeder runs in console with no resolved tenant,
     * so it correctly seeds the PLATFORM tier (tenant_id NULL) -- shipped
     * product defaults, never any tenant's own data.
     */
    public function run(): void
    {
        $current = CompanySetting::get('company_name');
        // v2.39.0: the long form is NOT the product name -- IOMS is a
        // standalone brand (see config/ioms.php `name`). It is listed as a
        // PREVIOUS default so re-seeding also CORRECTS an install that was
        // already seeded with it; a real customised company name is still
        // never touched.
        $previousDefaults = [null, 'Shipyard HSE Department', 'Shipyard Management System', 'Integrated Operations Management System'];

        if (in_array($current, $previousDefaults, true)) {
            CompanySetting::set('company_name', config('ioms.name'));
        }

        CompanySetting::set('company_subtitle', 'Industrial Operations Platform');
        CompanySetting::set('company_logo_path', CompanySetting::get('company_logo_path'));

        // Module toggle registry (v1.5.0): only seeded if it doesn't exist
        // yet, so re-running this seeder never overwrites a Super Admin's
        // module on/off choices. Reads the `modules` DB table (Task #42),
        // not config/modules.php anymore -- ModuleSeeder must run before
        // this seeder (see DatabaseSeeder's call order).
        if (CompanySetting::getUncached('enabled_modules') === null) {
            CompanySetting::set('enabled_modules', json_encode(Module::query()->pluck('key')->all()));
        }
    }
}
