<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PermitToWork;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * v2.37.0 (Master Audit). Regression test for the CONFIRMED PDF-vs-UI
 * date/time mismatch reported from production.
 *
 * Root cause: `APP_TIMEZONE` is UTC (correct for storage), but the PDF
 * blade formatted Carbon instances directly -- printing the raw UTC wall
 * clock -- while the React document view used `toLocaleString()` with no
 * `timeZone`, printing whatever timezone the VIEWER'S DEVICE was set to.
 * One stored instant, two different clock faces, differing by exactly the
 * viewer's UTC offset (8h for the WITA user who reported it).
 *
 * The real severity is not cosmetic: a Permit to Work is a controlled
 * safety document, and two people in different timezones were reading
 * different validity windows off the same permit.
 *
 * This test pins the PDF half of the fix against the REAL blade, so a
 * future edit that drops the timezone conversion fails here rather than
 * silently shipping wrong times on a safety document again.
 */
class PermitToWorkDocumentTimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_renders_validity_times_in_the_configured_display_timezone_not_utc(): void
    {
        config(['ioms.display_timezone' => 'Asia/Makassar']); // UTC+8

        $tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        $company = Company::withoutGlobalScopes()->create(['name' => 'C', 'tenant_id' => $tenant->id]);
        $user = User::create([
            'name' => 'Requester', 'email' => 'r@example.test', 'password' => bcrypt('x'),
            'role' => 'super_admin', 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        // 09:00 UTC == 17:00 in Asia/Makassar (UTC+8). The permit is
        // therefore valid until 17:00 as far as any human reader is
        // concerned; 09:00 must never appear on the document.
        $permit = PermitToWork::create([
            'ptw_number' => 'PTW-TZ-1',
            'company_id' => $company->id,
            'permit_type' => 'hot_work',
            'work_description' => 'Timezone regression fixture.',
            'start_datetime' => Carbon::parse('2026-09-03 01:37:00', 'UTC'),
            'end_datetime' => Carbon::parse('2026-09-03 09:00:00', 'UTC'),
            'requested_by' => $user->id,
            'status' => 'draft',
        ]);

        $permit->load('company', 'project', 'requester', 'gasTests', 'pic', 'personnel');

        $html = view('pdf.permit-to-work', [
            'permit' => $permit,
            'company' => $company,
            'documentTemplate' => null,
            'branding' => ['company_name' => 'C', 'logo_url' => null, 'address' => null],
            'rejectionReason' => null,
        ])->render();

        $this->assertStringContainsString('03 Sep 2026 17:00', $html, 'End time must render in the display timezone (17:00 WITA).');
        $this->assertStringContainsString('03 Sep 2026 09:37', $html, 'Start time must render in the display timezone (09:37 WITA).');

        $this->assertStringNotContainsString('03 Sep 2026 09:00', $html, 'The raw UTC end time must not appear on the document.');
        $this->assertStringNotContainsString('03 Sep 2026 01:37', $html, 'The raw UTC start time must not appear on the document.');
    }
}
