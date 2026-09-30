<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PtwQuotaConsumption;
use App\Services\PtwQuotaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * v2.86.0 -- PTW document quota: what is left, and how to buy more.
 *
 * TWO AUDIENCES, ONE PAGE, DIFFERENT RIGHTS. Anyone who may raise a permit
 * can SEE the balance, because being refused at the moment of writing a
 * permit without being able to find out why is the worst version of this
 * feature. Only an administrator may BUY, because buying spends money.
 *
 * NOTHING HERE GRANTS QUOTA. `purchase()` raises an invoice and hands the
 * customer to the existing payment path; the quota is credited by the
 * verified webhook and nowhere else, which is the same rule that has always
 * governed activation in IOMS. A customer who reaches a confirmation page
 * without paying receives nothing.
 */
class PtwQuotaController extends Controller
{
    public function __construct(private readonly PtwQuotaService $quota)
    {
    }

    /** The balance, the packs, and recent consumption. */
    public function show(Request $request): Response
    {
        $user = $request->user();
        $tenant = $user->tenant;

        abort_unless($user->canCreatePtw() || $user->canManageSystemSettings(), 403);

        $recent = PtwQuotaConsumption::query()
            ->where('tenant_id', $tenant?->id)
            ->with(['permit:id,ptw_number,title', 'consumer:id,name'])
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (PtwQuotaConsumption $c) => [
                'id' => $c->id,
                // The permit may have been deleted since. Its consumption
                // survives deliberately, so the row has to render without it.
                'ptw_number' => $c->permit?->ptw_number ?? 'PTW dihapus',
                'kind' => $c->kind,
                'by' => $c->consumer?->name,
                'at' => $c->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Ptw/Quota', [
            'balance' => $this->quota->balance($tenant),
            'packs' => $this->quota->topUpPacks(),
            'recent' => $recent,
            'canPurchase' => (bool) $user->canManageSystemSettings(),
        ]);
    }

    /**
     * Raises a top-up invoice. Grants nothing.
     *
     * The browser sends a PACK KEY, never an amount. The document count and
     * the price are both read from config, so a crafted form cannot buy 500
     * documents for one rupiah. This is the same rule the subscription
     * checkout has always followed.
     */
    public function purchase(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->canManageSystemSettings(), 403);

        $tenant = $user->tenant;
        $subscription = $tenant?->subscription;

        abort_unless($tenant && $subscription, 404);

        $validated = $request->validate([
            'pack' => ['required', 'string'],
        ]);

        $pack = $this->quota->topUpPack($validated['pack']);

        if (! $pack) {
            return back()->with('flash', ['error' => 'Paket tambahan kuota PTW tidak dikenali.']);
        }

        $invoice = DB::transaction(function () use ($tenant, $subscription, $pack, $user) {
            $invoice = Invoice::create([
                'invoice_number' => Invoice::generateNumber($tenant->id),
                'tenant_id' => $tenant->id,
                'subscription_id' => $subscription->id,
                // A one-off purchase belongs to no billing period, so
                // period_start/period_end stay null. The renewal machinery
                // keys on PURPOSE, so it will not mistake this for a period
                // it should extend.
                'purpose' => Invoice::PURPOSE_TOPUP,
                'amount' => $pack['price'],
                'currency' => $subscription->agreed_currency ?? 'IDR',
                'status' => Invoice::STATUS_ISSUED,
                'due_date' => now()->addDays(7),
                'notes' => "Tambahan {$pack['documents']} dokumen PTW.",
                'created_by' => $user->id,
            ]);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'kind' => InvoiceItem::KIND_PTW_TOPUP,
                'description' => "Tambahan kuota PTW, {$pack['documents']} dokumen",
                'quantity' => $pack['documents'],
                'unit_amount' => round($pack['price'] / $pack['documents'], 2),
                'amount' => $pack['price'],
                'sort_order' => 0,
            ]);

            return $invoice;
        });

        return redirect()->route('subscription.billing')
            ->with('flash', ['success' => "Tagihan {$invoice->invoice_number} dibuat. Kuota ditambahkan setelah pembayaran dikonfirmasi."]);
    }
}
