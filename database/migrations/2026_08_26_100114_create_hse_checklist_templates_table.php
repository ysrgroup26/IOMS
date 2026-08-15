<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v1.11.2 (Final Completion Pass, Part 9). LSA/FFA/PPE checklist
     * TEMPLATES -- the actual item lists behind the category labels the
     * previous pass added to `HseInspection::TYPES`. Reuses the existing
     * inspection engine end to end: a template is just a named, reusable
     * seed for `HseInspection.checklist_items` (already a JSON column,
     * already fully configurable) -- there is still only ONE inspection
     * table and ONE inspection UI. No separate FFA/LSA/PPE engines.
     *
     * `category` is validated in the controller against
     * `HseInspection::TYPES` (not duplicated as a DB enum) so a future
     * inspection type automatically becomes a valid template category
     * without a schema change.
     */
    public function up(): void
    {
        Schema::createIfMissing('hse_checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('category'); // matches HseInspection::TYPES
            $table->string('name');
            $table->json('items'); // [{label, description?}, ...]
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'category']);
        });

        // Seed one default template per existing company for the three
        // categories the spec gave concrete example item lists for --
        // additive data only, never overwrites a company's own later edits
        // (insertOrIgnore-style guard via a company+category existence check).
        $ffaItems = [
            ['label' => 'Equipment available at designated location'],
            ['label' => 'Correct location / not obstructed'],
            ['label' => 'Physical condition (no dents, corrosion, damage)'],
            ['label' => 'Safety seal / pin intact'],
            ['label' => 'Pressure gauge in operating range'],
            ['label' => 'Hose / nozzle condition'],
            ['label' => 'Signage visible and correct'],
            ['label' => 'Inspection tag up to date'],
        ];
        $lsaItems = [
            ['label' => 'Availability (correct quantity on site)'],
            ['label' => 'Correct storage location'],
            ['label' => 'Physical condition (no damage, rot, corrosion)'],
            ['label' => 'Accessibility (not blocked/locked away)'],
            ['label' => 'Identification / marking legible'],
        ];
        $ppeItems = [
            ['label' => 'Safety helmet condition & fit'],
            ['label' => 'Safety shoes condition & fit'],
            ['label' => 'Gloves appropriate for task & condition'],
            ['label' => 'Safety glasses / goggles condition'],
            ['label' => 'Coverall / protective clothing condition'],
        ];

        $companyIds = DB::table('companies')->pluck('id');
        foreach ($companyIds as $companyId) {
            foreach ([
                ['category' => 'ffa', 'name' => 'Standard FFA Inspection', 'items' => $ffaItems],
                ['category' => 'lsa', 'name' => 'Standard LSA Inspection', 'items' => $lsaItems],
                ['category' => 'ppe', 'name' => 'Standard PPE Inspection', 'items' => $ppeItems],
            ] as $template) {
                $exists = DB::table('hse_checklist_templates')
                    ->where('company_id', $companyId)
                    ->where('category', $template['category'])
                    ->exists();

                if (! $exists) {
                    DB::table('hse_checklist_templates')->insert([
                        'company_id' => $companyId,
                        'category' => $template['category'],
                        'name' => $template['name'],
                        'items' => json_encode($template['items']),
                        'is_active' => true,
                        'sort_order' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hse_checklist_templates');
    }
};
