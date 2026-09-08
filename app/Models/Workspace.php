<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Milestone 2 (Dynamic Workspace system, Task #43). DB-driven metadata
 * override for a `resources/js/lib/workspaces.js` WORKSPACES entry --
 * label/icon/order/active-state only. See the migration's own doc
 * comment for why the structural item list (routes, gates) stays in
 * code rather than becoming data-driven.
 */
class Workspace extends Model
{
    /**
     * v2.58.0 -- GLOBAL WORKSPACES ARE NOT SELLABLE, AND THAT WAS THE BUG.
     *
     * `tier` has distinguished 'department' from 'global' since this table
     * was created, but the ENTITLEMENT layer never read it: it treated
     * every workspace as a plan feature, including these two. They are not
     * features. `administration` is Settings, Users and Audit Logs;
     * `reports` is the Reports/Analytics/Report Center surface. They are
     * the application's own chrome — the parts of IOMS a customer needs in
     * order to run IOMS at all.
     *
     * Granting them per plan produced a customer who could reach the
     * Dashboard and saw an EMPTY SIDEBAR, because the sidebar's global
     * state is built from exactly these two workspaces. It also 403'd
     * Reports, Analytics, Report Center and Audit Logs, since both keys
     * appear in config('departments') and the workspace-entitlement
     * middleware is on by default.
     *
     * Every gate now asks this question instead of hardcoding a list.
     */
    public const TIER_GLOBAL = 'global';

    public const TIER_DEPARTMENT = 'department';

    /** The workspace keys no plan may withhold. Sold capacity is departments. */
    public static function globalKeys(): array
    {
        return static::query()->where('tier', self::TIER_GLOBAL)->pluck('key')->all();
    }

    public static function isGlobalKey(string $key): bool
    {
        return in_array($key, static::globalKeys(), true);
    }

    protected $fillable = [
        'key',
        'label',
        'icon',
        'tier',
        'is_core',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_core' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
