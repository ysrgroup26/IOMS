<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v2.52.0 -- department/domain names are the FULL English name.
 *
 * "HSE" and "HR" are industry shorthand, and shorthand is exactly what a
 * new user cannot decode. Worse, companies disagree about it: HSE, HSSE,
 * QHSE, EHS all name roughly the same function, and HR vs HC likewise.
 * Picking one abbreviation as the product's primary label silently takes
 * a side in a naming argument that has nothing to do with the software.
 *
 * The full name is unambiguous in every one of those organizations, so
 * that is what the navigation says. The abbreviation stays perfectly
 * usable in context -- "PTW", "HSE approval" -- and a tenant that wants
 * its own wording still has the existing per-tenant label override, which
 * is a DISPLAY layer, not a second technical module. IOMS stays one
 * standardized product.
 *
 * Only the display label changes. Workspace KEYS (`hse`, `hr`) are
 * untouched, because every grant row, route prefix, department map and
 * entitlement check is keyed on them -- renaming a key would be a
 * migration of the authorization model dressed up as a copy edit.
 */
return new class extends Migration
{
    private const LABELS = [
        'hse' => 'Health, Safety & Environment',
        'hr' => 'Human Resources',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('workspaces')) {
            return;
        }

        foreach (self::LABELS as $key => $label) {
            DB::table('workspaces')->where('key', $key)->update([
                'label' => $label,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('workspaces')) {
            return;
        }

        foreach (['hse' => 'HSE', 'hr' => 'Human Resources'] as $key => $label) {
            DB::table('workspaces')->where('key', $key)->update(['label' => $label, 'updated_at' => now()]);
        }
    }
};
