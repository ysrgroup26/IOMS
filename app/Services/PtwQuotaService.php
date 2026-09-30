<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PermitToWork;
use App\Models\PtwQuotaConsumption;
use App\Models\PtwQuotaGrant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v2.86.0 -- PTW DOCUMENT QUOTA, ACCOUNTED RATHER THAN COUNTED.
 *
 * One PTW document is consumed when a permit is successfully created, and
 * never again for anything that happens to it afterwards. Editing, viewing,
 * approving, closing and reopening are all free; the meter is on creation.
 *
 * TWO POOLS THAT STAY TWO POOLS. Included quota is granted per monthly
 * window and expires with it; purchased quota carries forward until spent.
 * The approved model rejects collapsing them into one remaining figure, and
 * it is right to: the expiry rule cannot be reconstructed from a single
 * number once the two have been added together.
 *
 * CONSUMPTION ORDER IS INCLUDED FIRST. A customer should lose the thing that
 * was going to expire anyway before the thing they paid extra for. Within a
 * pool the oldest grant goes first, so purchased quota is spent FIFO.
 *
 * DELETING A PERMIT DOES NOT REFUND. Consumption is its own row and is not
 * deleted with the permit, so create-delete-repeat cannot mint free
 * documents. The schema enforces this rather than this class: the
 * consumption table's foreign key to the permit is deliberately not a
 * cascade.
 *
 * A PERMIT CONSUMES EXACTLY ONCE. `ptw_quota_consumptions.permit_to_work_id`
 * is unique, so a retry, a double-submitted form or a second call cannot
 * charge twice.
 *
 * NULL MEANS UNMETERED, NOT ZERO. A tenant whose plan carries no
 * `ptw_included_monthly` -- Enterprise, and any plan an operator has not
 * given a figure -- is not metered at all. That follows the same fail-open
 * direction `EntitlementService` already takes for an unprovisioned tenant,
 * and it is the safe direction here too: the alternative is that a
 * misconfigured plan silently stops a safety-critical permit being raised.
 *
 * A METER MUST NOT FAIL OPEN ONCE IT IS ON, though. A tenant WITH a figure
 * is metered strictly, and exhaustion locks creation. The fail-open applies
 * to plans that do not sell the meter, never to a tenant that has spent
 * what it bought.
 */
class PtwQuotaService
{
    public const KIND_INCLUDED = 'included';

    public const KIND_PURCHASED = 'purchased';

    /**
     * The tenant's monthly included allowance, or null when the plan does
     * not meter PTW at all.
     */
    public function includedMonthlyAllowance(?Tenant $tenant): ?int
    {
        $package = $tenant?->subscription?->package;

        if (! $package) {
            return null;
        }

        $allowance = $package->ptw_included_monthly;

        return $allowance === null ? null : (int) $allowance;
    }

    /** Is this tenant metered at all? */
    public function isMetered(?Tenant $tenant): bool
    {
        return $tenant !== null && $this->includedMonthlyAllowance($tenant) !== null;
    }

    /**
     * Makes sure the tenant holds the included grant for the window that
     * contains `$at`, then returns the tenant's current balance.
     *
     * Granting lazily rather than only from the nightly job is deliberate.
     * The lifecycle command is the right place to do it on schedule, but a
     * tenant that signs up mid-month, or whose cron missed a night, must not
     * be unable to raise a permit because a scheduled task did not run. The
     * unique index on (tenant, term, sequence) makes the two paths safe to
     * race: whoever gets there first wins and the other is a no-op.
     */
    public function ensureIncludedGrant(Tenant $tenant, ?Carbon $at = null): void
    {
        $allowance = $this->includedMonthlyAllowance($tenant);

        if ($allowance === null || $allowance <= 0) {
            return;
        }

        $subscription = $tenant->subscription;

        if (! $subscription) {
            return;
        }

        $at ??= now();
        $window = $this->windowFor($subscription->starts_at, $at);

        if (! $window) {
            return;
        }

        [$start, $end, $sequence, $termStart] = $window;

        // Business annual buys fourteen months of ACCESS and twelve months
        // of PTW entitlement. Months thirteen and fourteen get no allocation.
        if ($subscription->billing_cycle === 'yearly' && $sequence >= (int) config('plans.ptw_annual_allocations', 12)) {
            return;
        }

        try {
            PtwQuotaGrant::create([
                'tenant_id' => $tenant->id,
                'kind' => self::KIND_INCLUDED,
                'quantity' => $allowance,
                'consumed' => 0,
                'effective_from' => $start,
                'expires_at' => $end,
                'term_started_at' => $termStart,
                'term_sequence' => $sequence,
                'note' => 'Kuota bulanan paket',
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // The unique slot index rejected a duplicate. That is the
            // mechanism working: another request or the nightly job already
            // granted this window. Anything else is a real error.
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }
        }
    }

    /**
     * The monthly window containing `$at`, anchored to the subscription's
     * own start day rather than to the calendar month.
     *
     * Anchoring to the subscription is what keeps "50 per month" honest for
     * a customer who subscribed on the 20th: they get a full window from the
     * 20th, not four days of quota and then a reset. It also makes an annual
     * subscription's twelve windows fall naturally out of the same rule, so
     * monthly and annual do not need two code paths.
     *
     * Returns [start, end, sequenceWithinTerm, termStart].
     *
     * @return array{0: Carbon, 1: Carbon, 2: int, 3: Carbon}|null
     */
    public function windowFor(?Carbon $termStart, Carbon $at): ?array
    {
        if (! $termStart) {
            return null;
        }

        $termStart = $termStart->copy();

        if ($at->lt($termStart)) {
            return null;
        }

        // Whole months elapsed since the term began. addMonths() clamps a
        // 31st to a short month the same way every month afterwards, so the
        // windows stay contiguous and never overlap or leave a gap.
        $sequence = 0;

        while (true) {
            $next = $termStart->copy()->addMonths($sequence + 1);

            if ($at->lt($next)) {
                break;
            }

            $sequence++;

            // A guard against an absurd clock or a corrupt start date
            // turning this into a very long loop.
            if ($sequence > 600) {
                return null;
            }
        }

        return [
            $termStart->copy()->addMonths($sequence),
            $termStart->copy()->addMonths($sequence + 1),
            $sequence,
            $termStart,
        ];
    }

    /**
     * Remaining documents in each pool, and the total.
     *
     * `metered` false means the plan does not sell a PTW meter, and every
     * other figure is null. A surface must render that as "not metered"
     * rather than as zero.
     *
     * @return array{metered: bool, included: ?int, purchased: ?int, total: ?int, included_allowance: ?int, period_ends_at: ?string, low: bool}
     */
    public function balance(?Tenant $tenant): array
    {
        if (! $tenant || ! $this->isMetered($tenant)) {
            return [
                'metered' => false,
                'included' => null,
                'purchased' => null,
                'total' => null,
                'included_allowance' => null,
                'period_ends_at' => null,
                'low' => false,
            ];
        }

        $this->ensureIncludedGrant($tenant);

        $included = $this->remainingIn($tenant, self::KIND_INCLUDED);
        $purchased = $this->remainingIn($tenant, self::KIND_PURCHASED);
        $total = $included + $purchased;

        $allowance = $this->includedMonthlyAllowance($tenant) ?? 0;
        $threshold = (float) config('saas.ptw_low_quota_threshold', 0.2);

        return [
            'metered' => true,
            'included' => $included,
            'purchased' => $purchased,
            'total' => $total,
            'included_allowance' => $allowance,
            'period_ends_at' => $this->currentIncludedGrant($tenant)?->expires_at?->toIso8601String(),
            // Measured against the included allowance rather than against
            // everything the tenant holds: a customer sitting on 2000
            // purchased documents is not low, and a customer with 5 left of
            // 50 is, whatever they bought last year.
            'low' => $total > 0 && $allowance > 0 && $total <= (int) ceil($allowance * $threshold),
        ];
    }

    /** Documents left in one pool, ignoring expired grants. */
    public function remainingIn(Tenant $tenant, string $kind): int
    {
        return (int) PtwQuotaGrant::query()
            ->where('tenant_id', $tenant->id)
            ->where('kind', $kind)
            ->live()
            ->get()
            ->sum(fn (PtwQuotaGrant $g) => max(0, $g->quantity - $g->consumed));
    }

    /** Total documents available across both pools. */
    public function remaining(?Tenant $tenant): ?int
    {
        if (! $tenant || ! $this->isMetered($tenant)) {
            return null;
        }

        $this->ensureIncludedGrant($tenant);

        return $this->remainingIn($tenant, self::KIND_INCLUDED)
            + $this->remainingIn($tenant, self::KIND_PURCHASED);
    }

    /** May this tenant create another PTW right now? */
    public function canCreate(?Tenant $tenant): bool
    {
        if (! $tenant || ! $this->isMetered($tenant)) {
            return true;
        }

        return ($this->remaining($tenant) ?? 0) > 0;
    }

    /**
     * Spends one document against the tenant's quota for `$permit`.
     *
     * Returns the consumption, or null when the tenant is not metered (so a
     * caller can treat "no meter" and "spent" identically without asking).
     * Throws when there is nothing left, which the caller is expected to
     * have already checked -- the throw is the backstop for a race, not the
     * user-facing path.
     *
     * Everything happens inside a transaction with the candidate grants
     * locked FOR UPDATE, because two field supervisors submitting the last
     * document at the same moment is a real thing that happens on a busy
     * site, and without the lock both would succeed.
     */
    public function consume(Tenant $tenant, PermitToWork $permit, ?User $by = null): ?PtwQuotaConsumption
    {
        if (! $this->isMetered($tenant)) {
            return null;
        }

        $this->ensureIncludedGrant($tenant);

        return DB::transaction(function () use ($tenant, $permit, $by) {
            // A permit may only ever spend one document. Checked inside the
            // transaction so a concurrent retry sees the same answer.
            $existing = PtwQuotaConsumption::where('permit_to_work_id', $permit->id)->first();

            if ($existing) {
                return $existing;
            }

            $grant = PtwQuotaGrant::query()
                ->where('tenant_id', $tenant->id)
                ->live()
                ->whereColumn('consumed', '<', 'quantity')
                // Included before purchased: 'included' sorts before
                // 'purchased' alphabetically, which is true but too subtle
                // to rely on, so the order is stated explicitly.
                ->orderByRaw("CASE WHEN kind = ? THEN 0 ELSE 1 END", [self::KIND_INCLUDED])
                ->orderBy('effective_from')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $grant) {
                throw new \RuntimeException('Kuota dokumen PTW sudah habis.');
            }

            $grant->increment('consumed');

            return PtwQuotaConsumption::create([
                'tenant_id' => $tenant->id,
                'ptw_quota_grant_id' => $grant->id,
                'permit_to_work_id' => $permit->id,
                'kind' => $grant->kind,
                'consumed_by' => $by?->id,
            ]);
        });
    }

    /**
     * Credits purchased quota. Called ONLY from the verified payment path.
     *
     * Idempotent on the invoice: a payment provider that delivers the same
     * notification twice, which they all do, must not credit twice. The
     * webhook layer already guards this with its own event table; this is
     * the second belt, on the resource itself.
     */
    public function creditPurchased(Tenant $tenant, int $documents, ?Invoice $invoice = null, ?string $note = null): ?PtwQuotaGrant
    {
        if ($documents <= 0) {
            return null;
        }

        if ($invoice) {
            $already = PtwQuotaGrant::where('tenant_id', $tenant->id)
                ->where('invoice_id', $invoice->id)
                ->first();

            if ($already) {
                return $already;
            }
        }

        return PtwQuotaGrant::create([
            'tenant_id' => $tenant->id,
            'kind' => self::KIND_PURCHASED,
            'quantity' => $documents,
            'consumed' => 0,
            'effective_from' => now(),
            // Never expires. This is the whole difference from included
            // quota, and it is expressed as the absence of a date rather
            // than as a very distant one.
            'expires_at' => null,
            'term_started_at' => null,
            'term_sequence' => null,
            'invoice_id' => $invoice?->id,
            'note' => $note ?? 'Pembelian tambahan kuota PTW',
        ]);
    }

    /** The top-up packs a customer may buy, priced from config. */
    public function topUpPacks(): array
    {
        $packs = [];

        foreach ((array) config('saas.ptw_topup_packs', []) as $key => $pack) {
            $documents = (int) ($pack['documents'] ?? $key);
            $price = (float) ($pack['price'] ?? 0);

            if ($documents <= 0 || $price <= 0) {
                continue;
            }

            $packs[] = [
                'key' => (string) $key,
                'documents' => $documents,
                'price' => $price,
                'formatted' => app(PricingService::class)->format($price),
                'per_document' => app(PricingService::class)->format(round($price / $documents)),
            ];
        }

        return $packs;
    }

    /** One pack by key, or null when the key is not a real pack. */
    public function topUpPack(?string $key): ?array
    {
        foreach ($this->topUpPacks() as $pack) {
            if ($pack['key'] === (string) $key) {
                return $pack;
            }
        }

        return null;
    }

    /** The included grant covering right now, if there is one. */
    private function currentIncludedGrant(Tenant $tenant): ?PtwQuotaGrant
    {
        return PtwQuotaGrant::query()
            ->where('tenant_id', $tenant->id)
            ->where('kind', self::KIND_INCLUDED)
            ->live()
            ->orderByDesc('effective_from')
            ->first();
    }

    private function isUniqueViolation(\Illuminate\Database\QueryException $e): bool
    {
        // 23000/23505 across MySQL, SQLite and Postgres.
        return in_array((string) $e->getCode(), ['23000', '23505'], true);
    }
}
