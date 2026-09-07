<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.54.0 -- THE SAME DEFECT v2.53.0 FIXED, IN THE COMPANIES TABLE
 * ITSELF. Found while writing the organizational-model tests: two
 * operating units in two DIFFERENT organizations could not both be
 * called "Yard Two".
 *
 * `companies.name` and `companies.code` have carried GLOBAL unique
 * indexes since the table was created, back when this was a
 * single-company application and "GAJ" was a fact about the world rather
 * than about one customer. Multi-tenancy arrived in Milestone 2 and the
 * indexes were never revisited. The application has known the right rule
 * the whole time -- SettingsController::storeCompanyEntity() scopes its
 * uniqueness rules to the tenant and its own comment says "two different
 * tenants CAN both have a company named GAJ". They could not. The
 * database refused.
 *
 * WHY THIS IS WORSE THAN THE v2.53.0 DOCUMENT-NUMBER DEFECT.
 *
 * That one broke a customer's first permit. This one breaks a customer's
 * ACCOUNT, and it breaks it AFTER THEY HAVE PAID.
 * TenantProvisioningService::activate() runs from a verified gateway
 * webhook and creates the organization's first operating unit named
 * after the registration, with a code derived from the first six
 * characters of the slugged name. So:
 *
 *   - Two customers registering the same company name -- "PT Maju Jaya"
 *     is not an unusual name -- collide on `companies_name_unique`.
 *   - Two customers whose names merely START alike -- "PT Bahari
 *     Nusantara" and "PT Bahari Sejahtera" both give "PTBAHA" -- collide
 *     on `companies_code_unique`.
 *   - Any name that slugs to nothing falls back to the literal 'COMP',
 *     so the second such customer collides outright.
 *
 * In every case the insert throws inside provisioning: payment
 * confirmed, invoice issued, no tenant, no login. Nothing retries into a
 * different name, because a duplicate key is not a transient failure.
 *
 * Both indexes become composite on (tenant_id, ...): an operating unit's
 * name and code are unique WITHIN an organization, which is exactly what
 * the validation rules already say and exactly what the product means.
 * `code` stays nullable -- SQL treats NULLs as distinct, so several units
 * may still have no code at all.
 *
 * Uses Schema::getIndexes() rather than information_schema, for the
 * reason recorded in CONVENTIONS.md: a raw information_schema query in a
 * migration breaks the entire SQLite test suite.
 */
return new class extends Migration
{
    public function up(): void
    {
        $existing = collect(Schema::getIndexes('companies'))->pluck('name')->all();

        Schema::table('companies', function (Blueprint $table) use ($existing) {
            if (in_array('companies_name_unique', $existing, true)) {
                $table->dropUnique('companies_name_unique');
            }
            if (in_array('companies_code_unique', $existing, true)) {
                $table->dropUnique('companies_code_unique');
            }
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->unique(['tenant_id', 'name'], 'companies_tenant_name_unique');
            $table->unique(['tenant_id', 'code'], 'companies_tenant_code_unique');
        });
    }

    /**
     * Deliberately does NOT restore the global indexes. Reversing this
     * would re-break provisioning, and on any deployment where two
     * organizations have since used the same name it could not be
     * applied at all. Dropping the composite ones is the honest reverse.
     */
    public function down(): void
    {
        $existing = collect(Schema::getIndexes('companies'))->pluck('name')->all();

        Schema::table('companies', function (Blueprint $table) use ($existing) {
            if (in_array('companies_tenant_name_unique', $existing, true)) {
                $table->dropUnique('companies_tenant_name_unique');
            }
            if (in_array('companies_tenant_code_unique', $existing, true)) {
                $table->dropUnique('companies_tenant_code_unique');
            }
        });
    }
};
