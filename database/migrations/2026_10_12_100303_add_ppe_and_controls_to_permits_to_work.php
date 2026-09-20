<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v2.73.0 -- PPE ON A PERMIT, FROM THE PPE MASTER THAT ALREADY EXISTS.
     *
     * A permit to work authorises hazardous work and did not record what
     * protective equipment that work requires. `precautions` is one free
     * text box, so PPE was either absent or buried in a sentence -- not
     * checkable at a gate, not listable, not reportable.
     *
     * NO NEW PPE MASTER. `ppe_types` has existed since the PPE module and
     * is the tenant's own list of equipment; JSA's `required_ppe` is the
     * odd one out, storing comma-separated free text, and v2.73.0 points
     * it at the same master rather than adding a third vocabulary. A
     * permit that says "Safety Harness" and a PPE issue record that says
     * "Safety harness" are the same control only if they are the same
     * row.
     *
     * REQUIRED vs CONFIRMED, as two separate lists, because they answer
     * two different questions at two different moments. `required_ppe_ids`
     * is written when the permit is RAISED -- what this job needs,
     * decided by whoever plans it. `confirmed_ppe_ids` is written at the
     * point of authorisation -- what was actually verified as present on
     * the people doing it. Collapsing them into one list loses the only
     * thing a permit is for: the difference between what should be true
     * and what somebody checked.
     *
     * JSON ARRAYS OF ppe_type IDs rather than a pivot table. These are a
     * snapshot on one document, never queried from the PPE side ("which
     * permits required a harness" is not a question this product asks),
     * and they must not cascade: deactivating a PPE type next year must
     * not silently rewrite what last year's permit said. A pivot with a
     * foreign key would make that a live join; JSON keeps it a record of
     * what was decided at the time. Same reasoning as JSA's `steps` and
     * HIRADC's `items`.
     */
    public function up(): void
    {
        Schema::table('permits_to_work', function (Blueprint $table) {
            $table->json('required_ppe_ids')->nullable()->after('precautions');
            $table->json('confirmed_ppe_ids')->nullable()->after('required_ppe_ids');

            // PPE the tenant has not (yet) put in its master. Recording it
            // as text is better than refusing it -- a permit blocked on a
            // settings screen is a permit written on paper instead -- but
            // it is kept in a clearly separate column so it reads as the
            // exception it is, and so the master-backed list stays clean.
            $table->json('additional_ppe')->nullable()->after('confirmed_ppe_ids');

            // The hazards this permit is actually controlling. `precautions`
            // said what to do about them without ever naming them, which
            // makes a permit impossible to check against its own JSA.
            $table->json('hazards')->nullable()->after('additional_ppe');
        });
    }

    public function down(): void
    {
        Schema::table('permits_to_work', function (Blueprint $table) {
            $table->dropColumn(['required_ppe_ids', 'confirmed_ppe_ids', 'additional_ppe', 'hazards']);
        });
    }
};
