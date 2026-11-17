<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Tenant;
use Database\Seeders\DemoTenantSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ADR 089 -- Master Admin > Aktifkan Sandbox.
 *
 * The Sandbox tenant is created by DemoTenantSeeder, which production never
 * ran: deploys do not seed, and `ioms-ops` does not allow `db:seed`. This one
 * button runs that seeder, in a transaction, idempotently (it is built from
 * firstOrCreate), so pressing it again refreshes nothing it should not.
 */
class PlatformSandboxController extends Controller
{
    public function activate(): RedirectResponse
    {
        try {
            DB::transaction(fn () => app(DemoTenantSeeder::class)->run());
        } catch (Throwable $e) {
            Log::error('Sandbox activation failed', ['error' => $e->getMessage()]);

            return back()->withErrors(['sandbox' => 'Sandbox gagal disiapkan: '.class_basename($e).'. Detail ada di log aplikasi.']);
        }
        $tenant = Tenant::where('slug', config('ioms.sandbox.tenant_slug'))->first();
        ActivityLog::record('updated', 'Sandbox IOMS disiapkan dari Master Admin', null, ['kind' => 'sandbox_activated', 'tenant_id' => $tenant?->id]);

        return back()->with('success', 'Sandbox aktif. Calon pelanggan kini bisa membukanya dari akun IOMS yang emailnya terverifikasi.');
    }
}
