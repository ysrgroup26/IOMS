<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v2.53.0 -- plan descriptions become English, and Professional stops
 * claiming multiple companies.
 *
 * Two problems in one string. The public pricing page is a marketing
 * surface and reads predominantly English; three long Indonesian
 * paragraphs sitting under English headings is not "Indonesian used
 * sparingly", it is a page that cannot decide what language it is in.
 *
 * More seriously, Professional's description said "untuk operasi yang
 * berkembang di beberapa perusahaan" — for operations growing across
 * SEVERAL companies — while the same release makes Professional a
 * one-company plan. A description that contradicts the capacity beside it
 * is worse than a vague one: it is the sentence a customer quotes back
 * when the product does not do what they bought.
 *
 * Same reasoning as every pricing migration in this codebase: the
 * `packages` table is what renders, so a seeder edit alone would leave
 * every existing deployment showing the old text.
 */
return new class extends Migration
{
    private const DESCRIPTIONS = [
        'starter' => 'Complete Health, Safety & Environment for a single company — incidents, observations, inspections, PPE, Permit To Work, CAPA and every other HSE module.',
        'professional' => 'Health, Safety & Environment plus Human Resources and cross-department Management visibility, for a single company with a growing operation.',
        'enterprise' => 'The full IOMS platform — every department (Health, Safety & Environment, Human Resources, Project Management, Logistics / PPIC, Warehouse, Procurement, Assets, Maintenance, Quality Control) with multi-company access and the highest standardized capacity.',
    ];

    public function up(): void
    {
        foreach (self::DESCRIPTIONS as $slug => $description) {
            DB::table('packages')->where('slug', $slug)
                ->update(['description' => $description, 'updated_at' => now()]);
        }
    }

    /** Not reversible: the previous Professional text misdescribed the plan it belongs to. */
    public function down(): void {}
};
