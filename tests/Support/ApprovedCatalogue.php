<?php

namespace Tests\Support;

/**
 * v2.60.0 -- THE APPROVED COMMERCIAL MODEL, WRITTEN DOWN ONCE.
 *
 * Before this class the four figures that define a plan were typed out in
 * three separate test files. Repricing to four tiers broke all three at
 * once, and each had to be found and corrected by hand -- which is exactly
 * the failure mode those tests exist to prevent, reproduced in the tests
 * themselves.
 *
 * So: one list. A test that wants to know what IOMS charges asks here.
 *
 * This is deliberately NOT read from `config/plans.php` or from the
 * migration. A test that derives its expectation from the code under test
 * asserts only that the code equals itself. These numbers are transcribed
 * from the approved commercial decision, and their whole job is to fail
 * when the code stops matching it.
 */
final class ApprovedCatalogue
{
    /**
     * slug => [monthly, yearly, included_users, max_companies]
     *
     * v2.82.0 -- THE APPROVED THREE-TIER MODEL. `included_users` is the
     * allowance the plan carries, NOT a ceiling: more active users are
     * purchasable at ADDITIONAL_USER_PRICE each per month, on every tier.
     *
     * `null` on either capacity column is this schema's "unlimited".
     *
     * The annual figures are not one rule -- see ANNUAL below, which is
     * the reason this could not stay a two-price table.
     */
    public const PLANS = [
        'starter' => [189000.0, 2268000.0, 3, 1],
        'professional' => [555000.0, 6105000.0, 10, 2],
        'business' => [1249000.0, 14988000.0, 25, 4],
    ];

    /**
     * slug => [paid months, service months]
     *
     * Starter pays twelve and gets twelve. Professional pays ELEVEN and
     * gets twelve -- a price discount. Business pays twelve and gets
     * FOURTEEN -- extra service, not a discount, which is why the service
     * figure has to be written down separately: it cannot be derived from
     * the two prices, because Business has no price saving at all.
     */
    public const ANNUAL = [
        'starter' => [12, 12],
        'professional' => [11, 12],
        'business' => [12, 14],
    ];

    /** Rp per additional ACTIVE user per month. One price, every plan. */
    public const ADDITIONAL_USER_PRICE = 50000.0;

    /**
     * Enterprise is RETIRED FROM SALE, not deleted: tenants are still
     * subscribed to it, so the row, its price and its grants stay. It must
     * not appear in the public catalogue.
     */
    public const RETIRED = ['enterprise'];

    /**
     * The DEPARTMENT workspaces each tier grants -- the sellable scope,
     * excluding the global chrome (`reports`, `administration`) that every
     * plan carries. `enterprise` is '*': every department that exists.
     */
    public const SCOPE = [
        'starter' => ['hse'],
        'professional' => ['hse', 'hr'],
        /*
         * v2.83.0 -- `management` joins Business, closing ADR 043's open
         * question. The approved scope has always read "... + Management";
         * until this release there was no such workspace to grant, so the
         * word named nothing and was deliberately left unimplemented rather
         * than faked with an existing key.
         */
        'business' => ['hse', 'hr', 'logistics', 'management', 'warehouse'],
    ];

    /** The tier presented as recommended. Exactly one, or none. */
    public const POPULAR = 'business';

    /** A PHPUnit data provider: one case per plan, named by slug. */
    public static function provider(): array
    {
        $cases = [];

        foreach (self::PLANS as $slug => [$monthly, $yearly, $users, $units]) {
            $cases[$slug] = [$slug, $monthly, $yearly, $users, $units];
        }

        return $cases;
    }

    /** Just the slugs, in ladder order. */
    public static function slugs(): array
    {
        return array_keys(self::PLANS);
    }
}
