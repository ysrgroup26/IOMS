<?php

namespace Tests\Feature;

use App\Models\PermitToWork;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression test for a real production bug: `PermitToWork` had no
 * `protected $table`, so Eloquent's default naive-pluralization inferred
 * `permit_to_works` instead of the actual migrated table `permits_to_work`
 * (see 2026_08_20_100067_create_permits_to_work_table and
 * docs/CONVENTIONS.md's Migrations pitfalls). This asserts the model
 * resolves to the real table so this mismatch can't silently regress.
 *
 * Deliberately doesn't round-trip a full create()/query() cycle: neither
 * `Company` nor `User` has a model factory in this codebase (only
 * `EmployeeFactory` exists), and fabricating valid fixture rows by hand
 * for both, without being able to run migrations or this suite in the
 * environment this fix was written in, risks a test that's wrong in a way
 * nothing here could catch. The table-name assertion below is the actual
 * regression this bug was about and needs no fixture data at all.
 *
 * NOTE: this project's test harness itself is not fully bootstrapped as
 * of this fix (no phpunit.xml, no tests/TestCase.php), and no php/composer
 * binary was available in the environment this fix was written in, so
 * this test has been verified by careful reading against the schema, not
 * by an actual run. Flagged separately in the accompanying commit message
 * rather than silently building out a full test harness as a side effect
 * of this one-line model fix.
 */
class PermitToWorkTableMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_permit_to_work_model_maps_to_the_real_migrated_table(): void
    {
        $this->assertSame('permits_to_work', (new PermitToWork)->getTable());
        $this->assertTrue(Schema::hasTable('permits_to_work'));
        $this->assertFalse(Schema::hasTable('permit_to_works'));
    }
}
