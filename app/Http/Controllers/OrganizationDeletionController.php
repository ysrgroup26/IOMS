<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantDeletionService;
use App\Support\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * ADR 088 -- the customer deletes their own organization (Billing page).
 * Named under `subscription.` so it stays reachable while the subscription is
 * read-only (EnforceSubscriptionWriteAccess): leaving must never require paying.
 */
class OrganizationDeletionController extends Controller
{
    public function __construct(private TenantDeletionService $deletions) {}

    public function request(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'organization_name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);
        $tenant = $this->tenant($request);
        try {
            $this->deletions->requestByCustomer($tenant, $request->user(), $data['organization_name'], $data['password']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['organization_name' => $e->getMessage()]);
        }

        return back()->with('success', 'Penghapusan organisasi dijadwalkan '.TenantDeletionService::CUSTOMER_HOLD_DAYS.' hari lagi. Anda masih bisa membatalkannya sampai tanggal itu.');
    }

    public function cancel(Request $request): RedirectResponse
    {
        $this->deletions->cancel($this->tenant($request), $request->user());

        return back()->with('success', 'Penghapusan organisasi dibatalkan. Tidak ada data yang dihapus.');
    }

    private function tenant(Request $request): Tenant
    {
        abort_unless($request->user()->role === User::ROLE_SUPER_ADMIN, 403);
        $id = app(CurrentTenant::class)->id();
        abort_if($id === null || $id !== $request->user()->tenant_id, 404);

        return Tenant::findOrFail($id);
    }
}
