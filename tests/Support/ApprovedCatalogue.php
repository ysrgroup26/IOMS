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
     * slug => [monthly, yearly, max_users, max_companies]
     *
     * `null` on either capacity column is this schema's "unlimited".
     * Annual is monthly x 10 on every tier (~16.67%, shown as 17%).
     */
    public const PLANS = [
        'starter' => [299000.0, 2990000.0, 10, 1],
        'professional' => [799000.0, 7990000.0, 50, 2],
        'business' => [1499000.0, 14990000.0, 150, 4],
        'enterprise' => [2499000.0, 24990000.0, null, null],
    ];

    /**
     * The DEPARTMENT workspaces each tier grants -- the sellable scope,
     * excluding the global chrome (`reports`, `administration`) that every
     * plan carries. `enterprise` is '*': every department that exists.
     */
    public const SCOPE = [
        'starter' => ['hse'],
        'professional' => ['hse', 'hr'],
        'business' => ['hse', 'hr', 'project-management', 'logistics', 'procurement'],
        'enterprise' => '*',
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
