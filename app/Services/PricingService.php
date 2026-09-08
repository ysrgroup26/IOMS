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
            // v2.53.0: `max_ptw_users` is no longer sent. PTW Access is not
            // a purchasable capacity any more, so a pricing surface has
            // nothing to say about it -- capacity is TOTAL USERS.
            'monthly' => $this->money($package->price_monthly, $package->currency, $package->is_custom),
            'yearly' => $this->money($package->price_yearly, $package->currency, $package->is_custom),
            // v2.56.0: the annual saving, derived ONCE and server-side --
            // see annualSaving() for why it stopped being a JSX expression.
            'annual_saving' => $this->annualSaving($package),
            // v2.60.0 -- positioning and the recommended flag come from
            // config/plans.php, beside the scope they describe, so every
            // pricing surface states the same thing and no page keeps its
            // own copy. `is_popular` exists so the UI never has to guess
            // which card to emphasise from its position in the array --
            // which broke the moment a fourth tier arrived.
            'positioning' => config("plans.positioning.{$package->slug}"),
            'is_popular' => config('plans.popular') === $package->slug,
            // The complete grant, as provisioned.
            'workspaces' => $this->labelsFor(Workspace::class, $package->defaultWorkspaceKeys()),
            // v2.60.0: the part that differs between tiers -- what a pricing
            // card should list. Reports and Settings are not a tier's
            // selling point; every plan has them.
            'department_workspaces' => $this->labelsFor(Workspace::class, $package->departmentWorkspaceKeys()),
            'modules' => $this->labelsFor(Module::class, $package->defaultModuleKeys()),
        ];
    }

    /**
     * v2.56.0 -- THE ANNUAL SAVING, DERIVED ONCE.
     *
     * The percentage used to be computed inline in Pricing.jsx:
     *
     *     Math.round(100 - (plan.yearly.amount / (plan.monthly.amount * 12)) * 100)
     *
     * which was correct, but it lived on ONE page. Get Started and the
     * checkout order summary showed the annual figure with no indication
     * that it was already discounted, so a buyer comparing the two cycles
     * had to do the arithmetic themselves at the exact moment they were
     * deciding to pay. Any second surface that wanted the number would have
     * had to copy the expression, and a copied derivation is how two pages
     * eventually disagree about the same price.
     *
     * It is now computed here, from the package's OWN two prices, and every
     * surface reads it. Nothing is invented: `monthly_equivalent` is simply
     * twelve monthly payments, `amount` is the difference, and `percent` is
     * that difference as a share of the twelve payments. If a plan has no
     * usable pair of prices -- custom, free, or missing either figure --
     * this returns null and the surfaces render nothing rather than a zero.
     *
     * Rounded DOWN, so the advertised saving is never larger than the real
     * one: 16.67% is presented as 16%... except that rounding a genuine
     * 16.67 to 16 understates it, so the value is rounded normally to 17
     * and the exact rupiah figure is shown beside it. The dependable number
     * on the page is the amount; the percentage is the summary.
     */
    private function annualSaving(Package $package): ?array
    {
        if ($package->is_custom) {
            return null;
        }

        $monthly = (float) ($package->price_monthly ?? 0);
        $yearly = (float) ($package->price_yearly ?? 0);

        if ($monthly <= 0 || $yearly <= 0) {
            return null;
        }

        $equivalent = $monthly * 12;
        $saved = $equivalent - $yearly;

        // An annual price at or above twelve monthly payments is not a
        // saving, and must never be presented as one.
        if ($saved <= 0) {
            return null;
        }

        return [
            'monthly_equivalent' => $equivalent,
            'monthly_equivalent_formatted' => $this->format($equivalent, $package->currency),
            'amount' => $saved,
            'formatted' => $this->format($saved, $package->currency),
            'percent' => (int) round(($saved / $equivalent) * 100),
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
