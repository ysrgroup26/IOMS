<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantRegistration;
use App\Services\TenantDeletionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

/**
 * ADR 087 -- Master Admin's deletion actions. Behind `role:platform_admin`.
 * The rules live in TenantDeletionService; this only turns them into requests.
 */
class PlatformTenantDeletionController extends Controller
{
    public function __construct(private TenantDeletionService $deletions) {}

    /** A test organization that never paid: removed now, after typing its slug. */
    public function purgeTest(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate(['slug' => ['required', 'string', 'max:255']]);
        try {
            $this->deletions->purgeTestTenant($tenant, $data['slug'], $request->user());
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->withErrors(['slug' => $e->getMessage()]);
        }

        return redirect()->route('platform.tenants')->with('success', 'Tenant uji dihapus permanen. Email penggunanya bisa didaftarkan lagi.');
    }

    /** Anybody else: held 90 days, administrators told now and reminded. */
    public function schedule(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']]);
        try {
            $this->deletions->schedule($tenant, $data['reason'], $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('success', 'Penghapusan dijadwalkan '.TenantDeletionService::HOLD_DAYS.' hari lagi. Administrator tenant sudah diberi tahu lewat email.');
    }

    public function cancel(Request $request, Tenant $tenant): RedirectResponse
    {
        $this->deletions->cancel($tenant, $request->user());

        return back()->with('success', 'Penghapusan dibatalkan. Administrator tenant diberi tahu.');
    }

    /** A registration that never paid and never became an organization. */
    public function purgeRegistration(Request $request, TenantRegistration $registration): RedirectResponse
    {
        try {
            $this->deletions->purgeTestRegistration($registration, $request->user());
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->withErrors(['registration' => $e->getMessage()]);
        }

        return back()->with('success', 'Pendaftaran dihapus permanen. Emailnya bisa didaftarkan lagi.');
    }
}
