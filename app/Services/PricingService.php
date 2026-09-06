<?php

namespace App\Services;

use App\Models\Module;
use App\Models\Package;
use App\Models\Workspace;
use Illuminate\Support\Collection;

/**
 * v2.14.0 (SaaS Productization / Pricing Foundation). The single place
 * that turns a raw `Package` row into the data shape a pricing/plan
 * comparison UI actually needs -- amounts alongside a locale-formatted
 * string, and each plan's real Workspace/Module grant (via
 * `Package::defaultWorkspaceKeys()`/`defaultModuleKeys()`, the same
 * mapping `PlatformController::storeTenant()` already uses to provision a
 * new tenant) resolved to their human-readable labels, so a comparison
 * table is never hand-maintained separately from what a tenant would
 * actually receive.
 *
 * Deliberately reuses `Package`/`Workspace`/`Module` as-is -- no new
 * table, no duplicated pricing/feature list. Every amount comes straight
 * from the `packages` table; this service formats, it never invents a
 * number (see this phase's own "DO NOT INVENT FINAL PRICES" rule --
 * plans with a null/zero price or `is_custom=true` are surfaced as such,
 * never given a fabricated figure).
 */
class PricingService
{
    /**
     * The plans a tenant-facing Plans/pricing page should show: active
     * AND public, ordered the same way the Platform Admin's own Plans
     * list is ordered (`sort_order`), so both surfaces agree on plan
     * ordering without a second convention.
     */
    public function publicPlans(): Collection
    {
        return Package::query()
            ->active()
            ->public()
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Package $package) => $this->summarize($package))
            ->values();
    }

    /** Same shape as publicPlans(), for a single Package -- used to render a tenant's OWN current plan even if it happens to be internal-only/inactive (a grandfathered plan should still describe itself correctly to the tenant using it). */
    public function summarize(Package $package): array
    {
        return [
            'id' => $package->id,
            'name' => $package->name,
            'slug' => $package->slug,
            'description' => $package->description,
            'is_custom' => $package->is_custom,
            'is_public' => $package->is_public,
            'trial_days' => $package->trial_days,
            'max_users' => $package->max_users,
            'max_companies' => $package->max_companies,
            // v2.52.0. Without this the pricing page could not tell a capped
            // plan from an uncapped one and printed "PTW Access untuk
            // seluruh akun" for every tier -- advertising a quota the
            // entitlement layer would refuse.
            'max_ptw_users' => $package->max_ptw_users,
            'monthly' => $this->money($package->price_monthly, $package->currency, $package->is_custom),
            'yearly' => $this->money($package->price_yearly, $package->currency, $package->is_custom),
            'workspaces' => $this->labelsFor(Workspace::class, $package->defaultWorkspaceKeys()),
            'modules' => $this->labelsFor(Module::class, $package->defaultModuleKeys()),
        ];
    }

    /**
     * A single amount, formatted for display alongside the raw numeric
     * value -- the frontend never formats currency itself (see this
     * phase's own "the frontend should consume {amount, formatted_amount}"
     * requirement), so a future payment-gateway integration can display
     * and charge from this exact same source without a second formatter
     * being written for it.
     */
    private function money(?string $amount, string $currency, bool $isCustom): array
    {
        if ($isCustom || $amount === null) {
            return [
                'amount' => null,
                'currency' => $currency,
                'formatted' => 'Hubungi Kami',
            ];
        }

        $numeric = (float) $amount;

        return [
            'amount' => $numeric,
            'currency' => $currency,
            // v2.50.0: IDR renders as "Rp" -- "IDR 999.000" is how a ledger
            // writes it, "Rp999.000" is how an Indonesian buyer reads it.
            // Every other currency keeps the ISO code prefix.
            'formatted' => $numeric == 0.0
                ? 'Gratis'
                : ($currency === 'IDR' ? 'Rp' : $currency.' ').number_format($numeric, 0, ',', '.'),
        ];
    }

    /**
     * v2.51.0. The ONE server-side answer to "what does this plan cost on
     * this cycle" -- read straight from the packages table.
     *
     * Every price a customer is charged goes through here. Nothing in the
     * checkout path ever trusts an amount that arrived in a request: the
     * browser sends a plan slug and a cycle, and the number comes from the
     * database. That is what stops a crafted form from buying Enterprise
     * for one rupiah.
     */
    public function amountFor(Package $package, string $cycle): float
    {
        $amount = $cycle === 'monthly' ? $package->price_monthly : $package->price_yearly;

        return (float) ($amount ?? 0);
    }

    /** Formats an amount the same way every pricing surface does, so an invoice and a pricing card can never disagree. */
    public function format(float $amount, string $currency = 'IDR'): string
    {
        return $this->money((string) $amount, $currency, false)['formatted'];
    }

    /**
     * Resolves a plan slug submitted by a browser against the REAL public
     * catalog. Returns null for anything that is not a currently public,
     * active plan -- so an unknown, private, retired or hand-crafted slug
     * can never enter checkout.
     */
    public function resolvePublicPackage(?string $slug): ?Package
    {
        if (! $slug) {
            return null;
        }

        return Package::query()->active()->public()->where('slug', $slug)->first();
    }

    /** Normalizes a submitted billing cycle to one of the two IOMS supports. */
    public function normalizeCycle(?string $cycle): string
    {
        return $cycle === 'monthly' ? 'monthly' : 'yearly';
    }

    /** @param class-string<Workspace>|class-string<Module> $model */
    private function labelsFor(string $model, array $keys): array
    {
        if (empty($keys)) {
            return [];
        }

        return $model::whereIn('key', $keys)
            ->orderBy('sort_order')
            ->pluck('label')
            ->values()
            ->all();
    }
}
