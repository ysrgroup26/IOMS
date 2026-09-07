<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v2.54.0 -- THE ORGANIZATIONAL MODEL, stated once and in one direction.
 *
 *     IOMS  ->  Organization  ->  Operating Unit(s)  ->  Departments
 *
 * The mapping onto what already exists, decided by inspecting the data
 * rather than by assumption:
 *
 *   Tenant        = ORGANIZATION   (the customer that subscribes)
 *   Company       = OPERATING UNIT (GAJ, MTC -- a yard, a division, a site)
 *   Department    = DEPARTMENT     (unchanged)
 *
 * The production database made the decision easy: GAJ and MTC are two
 * `companies` rows under ONE tenant. They were never two customers and
 * were never billed separately -- they are two operating units of a
 * single organization, exactly as the product model now says. So no data
 * is moved, nothing is deleted, and no table is renamed: `companies` is
 * the operating-unit table and always was. Renaming it would touch forty
 * controllers and every FK in the schema to buy a word.
 *
 * TWO THINGS ACTUALLY CHANGE HERE.
 *
 * 1. LEGAL ENTITY becomes an optional ATTRIBUTE of an operating unit, not
 *    a new level in the hierarchy. Most customers have one legal company
 *    and several operating units; a few Enterprise customers have several
 *    registered entities. Modelling that as a fourth table would impose a
 *    join on every customer to serve the minority -- and would be a
 *    second, competing owner of "who does this record belong to" beside
 *    `company_id`. Two nullable columns say the same thing without
 *    changing a single query: an operating unit MAY declare the legal
 *    entity it trades as, and documents can print it.
 *
 * 2. PROFESSIONAL GOES FROM ONE OPERATING UNIT TO TWO. That is a
 *    commercial decision, recorded: Professional is the tier for an
 *    organization running a second yard/site, which is the most common
 *    reason to outgrow Starter. It never meant "two companies you may
 *    bill separately" -- capacity is measured in operating units inside
 *    ONE organization, and `packages.max_companies` is that number.
 *    (The column keeps its name for the same reason the table does.)
 *
 * Starter stays at one. Enterprise stays null = many.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // The registered company this operating unit trades as, when
            // the organization has more than one. NULL -- the case for
            // nearly every customer -- means "the organization itself".
            $table->string('legal_entity_name')->nullable()->after('code');
            // NPWP / NIB / company registration number, printed on
            // documents that need it. Free text on purpose: the format
            // differs by jurisdiction and IOMS does not validate it.
            $table->string('legal_entity_registration', 100)->nullable()->after('legal_entity_name');
        });

        DB::table('packages')->where('slug', 'professional')
            ->update(['max_companies' => 2, 'updated_at' => now()]);

        // Descriptions restated in the platform's own vocabulary. The
        // `packages` table is what renders on the public pricing page, so
        // a seeder edit alone would leave every existing deployment
        // describing itself in the old, wrong terms.
        $descriptions = [
            'starter' => 'Complete Health, Safety & Environment for one operating unit — incidents, observations, inspections, PPE, Permit To Work, CAPA and every other HSE module.',
            'professional' => 'Health, Safety & Environment plus Human Resources and cross-department Management visibility, for an organization running up to two operating units.',
            'enterprise' => 'The full IOMS platform — every department (Health, Safety & Environment, Human Resources, Project Management, Logistics / PPIC, Warehouse, Procurement, Assets, Maintenance, Quality Control) across multiple operating units, with per-unit authorization and legal entity structures where they apply.',
        ];

        foreach ($descriptions as $slug => $description) {
            DB::table('packages')->where('slug', $slug)
                ->update(['description' => $description, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['legal_entity_name', 'legal_entity_registration']);
        });

        DB::table('packages')->where('slug', 'professional')
            ->update(['max_companies' => 1, 'updated_at' => now()]);
    }
};
