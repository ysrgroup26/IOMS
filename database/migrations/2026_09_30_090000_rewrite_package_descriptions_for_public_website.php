<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v2.85.0 -- THE PLAN DESCRIPTIONS WERE PUBLIC COPY NOBODY WAS AUDITING.
 *
 * `packages.description` is rendered on the pricing cards, on the landing
 * page and inside checkout. It is therefore website copy, but it lives in a
 * database column rather than in `PublicController`, so every copy pass this
 * project has run reviewed the constants and never saw these four strings.
 *
 * Three defects, all of them customer-visible:
 *
 *   1. RETIRED NAMING. Business described itself as "Logistics / PPIC and
 *      Warehouse". The tier is sold as Warehouse Logistics, one domain, and
 *      it has granted `management` since v2.83.0 -- which the description
 *      never mentioned at all, so the card omitted a whole workspace the
 *      customer is paying for.
 *
 *   2. EM DASH. All four carried the character the public copy rule
 *      forbids. The website's own em-dash check missed them because Inertia
 *      serializes props as JSON, where the character appears as the escape
 *      sequence — and not as the literal a grep was looking for.
 *
 *   3. ENTERPRISE DESCRIBED A PRODUCT THAT NO LONGER EXISTS. It claimed
 *      "every operational department", but v2.84.0 narrowed Enterprise to
 *      exactly what Business grants -- its remaining difference is capacity,
 *      not scope. Enterprise is retired from sale, so this text is not on
 *      the public site; it is still shown to the tenants who are subscribed
 *      to it, on their own Billing and Plans pages, which is precisely why
 *      it has to be true.
 *
 * Data only. No schema change, no price change, no entitlement change: what
 * each plan GRANTS is `config/plans.php` and what it COSTS is the columns
 * beside this one, and neither is touched here.
 *
 * Reversible. `down()` restores the exact previous strings.
 */
return new class extends Migration
{
    /** slug => [new description, previous description] */
    private const DESCRIPTIONS = [
        'starter' => [
            'Digitalize Health, Safety & Environment for one operating unit: incidents, observations, inspections, PPE, Permit To Work, CAPA and every other HSE module.',
            'Digitalize Health, Safety & Environment for one operating unit — incidents, observations, inspections, PPE, Permit To Work, CAPA and every other HSE module.',
        ],
        'professional' => [
            'Health, Safety & Environment plus People and Workforce: employees, competency and certificate expiry, shifts, rosters and leave, for an organization running up to two operating units.',
            'Health, Safety & Environment plus People and Workforce — employees, competency and certificate expiry, shifts and rosters, leave — for an organization running up to two operating units.',
        ],
        'business' => [
            'Health, Safety & Environment and People, plus Warehouse Logistics and Management reporting, with the company-wide Dashboard across up to four operating units.',
            'Health, Safety & Environment and People, plus Logistics / PPIC and Warehouse — so work, materials and stock stop living in separate systems.',
        ],
        'enterprise' => [
            'The four operational workspaces with no stated ceiling on operating units or active user accounts, for organizations that need per-unit authorization and legal entity structures.',
            'The complete IOMS platform — every operational department, unlimited operating units and unlimited user accounts, with per-unit authorization and legal entity structures where they apply.',
        ],
    ];

    public function up(): void
    {
        foreach (self::DESCRIPTIONS as $slug => [$new, $_old]) {
            DB::table('packages')->where('slug', $slug)->update(['description' => $new]);
        }
    }

    public function down(): void
    {
        foreach (self::DESCRIPTIONS as $slug => [$_new, $old]) {
            DB::table('packages')->where('slug', $slug)->update(['description' => $old]);
        }
    }
};
