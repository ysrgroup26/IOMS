<?php

use App\Models\Workspace;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v2.84.1 -- ONE WORKSPACE, ONE NAME: "Warehouse Logistics".
     *
     * The product carried two names for one thing. "Logistics / PPIC" was the
     * original department; v2.84.0 renamed it "Logistics / Warehouse" when
     * the standalone Warehouse shell retired into it. Neither is the name the
     * workspace is sold under, and a customer reading "Warehouse" in one
     * place and "Logistics / PPIC" in another reasonably concludes there are
     * two.
     *
     * A DATA MIGRATION, because the label in this table OVERRIDES the one in
     * `resources/js/lib/workspaces.js` (see `applyCatalog()`). Shipping the
     * code change alone would leave every existing install showing the old
     * name -- the same reason the v2.84.0 rename needed a migration, and the
     * same reason CONVENTIONS.md records from v2.51.0 about prices.
     *
     * BOTH PRIOR DEFAULTS ARE MATCHED. An install that went straight from
     * v2.83.0 to v2.84.1 never saw "Logistics / Warehouse", so renaming only
     * from that value would silently miss it.
     *
     * A TENANT'S OWN WORDING IS NOT OVERWRITTEN. A customer who renamed the
     * workspace for themselves in Settings meant it, and this only corrects
     * rows still carrying a default IOMS shipped.
     *
     * The KEY is untouched. `logistics` is what every grant row, prefix map,
     * route and entitlement check is keyed on; renaming it would be a
     * migration of the authorization model dressed up as a copy edit.
     */
    private const OLD_LABELS = ['Logistics / Warehouse', 'Logistics / PPIC'];

    private const NEW_LABEL = 'Warehouse Logistics';

    public function up(): void
    {
        if (! Schema::hasTable('workspaces')) {
            return;
        }

        Workspace::where('key', 'logistics')
            ->whereIn('label', self::OLD_LABELS)
            ->update(['label' => self::NEW_LABEL]);
    }

    /**
     * Rolls back to the label v2.84.0 shipped, not to the v2.83.0 one -- a
     * down migration returns to the previous release, and reaching past it
     * would leave the catalogue in a state no release ever had.
     */
    public function down(): void
    {
        if (! Schema::hasTable('workspaces')) {
            return;
        }

        Workspace::where('key', 'logistics')
            ->where('label', self::NEW_LABEL)
            ->update(['label' => 'Logistics / Warehouse']);
    }
};
