<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Rfq;
use App\Models\RfqVendor;
use App\Models\VendorQuotation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Milestone 4, Workstream C3 (Vendor Quotation). Nested under an RFQ -- store/update/destroy only, comparison itself renders on Rfqs/Show.jsx. */
class VendorQuotationController extends Controller
{
    public function store(Request $request, Rfq $rfq): RedirectResponse
    {
        abort_unless($request->user()->canManageProcurement(), 403);
        abort_unless(Company::query()->pluck('id')->contains($rfq->company_id), 404);

        $tenantVendorIds = $rfq->rfqVendors()->pluck('vendor_id');

        $data = $request->validate([
            'vendor_id' => ['required', Rule::in($tenantVendorIds)],
            'vendor_reference_number' => ['nullable', 'string', 'max:100'],
            'quotation_date' => ['required', 'date'],
            'valid_until' => ['nullable', 'date'],
            'currency' => ['required', 'string', 'max:10'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit' => ['required', 'string', 'max:50'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax' => ['nullable', 'numeric', 'min:0'],
            'shipping_cost' => ['nullable', 'numeric', 'min:0'],
            'other_charges' => ['nullable', 'numeric', 'min:0'],
            'lead_time_days' => ['nullable', 'integer', 'min:0'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'delivery_terms' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,xlsx', 'max:10240'],
        ]);

        $subtotal = collect($data['items'])->sum(fn ($i) => $i['quantity'] * $i['unit_price']);
        $discountTotal = collect($data['items'])->sum(fn ($i) => $i['discount'] ?? 0);
        $taxTotal = collect($data['items'])->sum(fn ($i) => $i['tax'] ?? 0);
        $shipping = $data['shipping_cost'] ?? 0;
        $other = $data['other_charges'] ?? 0;
        $total = $subtotal - $discountTotal + $taxTotal + $shipping + $other;

        $attachmentPath = null;
        if ($request->hasFile('attachment')) {
            $attachmentPath = $request->file('attachment')->store('uploads/vendor-quotations', 'public');
        }

        $quotation = VendorQuotation::updateOrCreate(
            ['rfq_id' => $rfq->id, 'vendor_id' => $data['vendor_id']],
            [
                ...$data,
                'company_id' => $rfq->company_id,
                'subtotal' => $subtotal,
                'discount_amount' => $discountTotal,
                'tax_amount' => $taxTotal,
                'shipping_cost' => $shipping,
                'other_charges' => $other,
                'total_amount' => $total,
                'status' => VendorQuotation::STATUSES[0],
                'attachment_path' => $attachmentPath ?? optional(VendorQuotation::where('rfq_id', $rfq->id)->where('vendor_id', $data['vendor_id'])->first())->attachment_path,
            ]
        );

        RfqVendor::where('rfq_id', $rfq->id)->where('vendor_id', $data['vendor_id'])->update(['status' => 'responded']);

        ActivityLog::record('created', "Recorded quotation from vendor for RFQ {$rfq->rfq_number}.", $rfq);

        return back()->with('success', 'Quotation recorded.');
    }

    public function destroy(Rfq $rfq, VendorQuotation $quotation, Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageProcurement(), 403);
        abort_unless($quotation->rfq_id === $rfq->id, 404);
        abort_unless(Company::query()->pluck('id')->contains($rfq->company_id), 404);

        if ($quotation->attachment_path) {
            Storage::disk('public')->delete($quotation->attachment_path);
        }
        $quotation->delete();

        return back()->with('success', 'Quotation removed.');
    }
}
