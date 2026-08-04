<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\GoodsReceipt;
use App\Models\MaterialRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class GoodsReceiptController extends Controller
{
    public function index(Request $request): Response
    {
        $goodsReceipts = GoodsReceipt::query()
            ->with('materialRequest:id,request_number', 'project:id,name', 'receiver:id,name')
            ->withCount('items')
            ->when($request->input('search'), fn ($q, $v) => $q->where('receipt_number', 'like', "%{$v}%"))
            ->latest('received_date')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('GoodsReceipts/Index', [
            'goodsReceipts' => $goodsReceipts,
            'filters' => $request->only('search'),
            'can' => ['manage' => $request->user()->canManageGoodsReceipts()],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('GoodsReceipts/Form', [
            // Only requests that have actually reached Approved/Processing/Completed
            // are eligible to receive against -- receiving against a Draft or
            // still-pending request would be recording goods that were never
            // authorized.
            'materialRequests' => MaterialRequest::whereIn('status', ['approved', 'processing', 'completed'])
                ->orderByDesc('request_date')
                ->get(['id', 'request_number']),
            'projects' => Project::orderBy('name')->get(['id', 'name']),
            'receiptNumber' => GoodsReceipt::generateReceiptNumber(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageGoodsReceipts(), 403);

        $data = $request->validate([
            'received_date' => ['required', 'date'],
            'material_request_id' => ['nullable', 'exists:material_requests,id'],
            'project_id' => ['nullable', 'exists:projects,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity_received' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit' => ['required', 'string', 'max:50'],
        ]);

        $goodsReceipt = DB::transaction(function () use ($data, $request) {
            $goodsReceipt = GoodsReceipt::create([
                'receipt_number' => GoodsReceipt::generateReceiptNumber(),
                'received_date' => $data['received_date'],
                'material_request_id' => $data['material_request_id'] ?? null,
                'project_id' => $data['project_id'] ?? null,
                'received_by' => $request->user()->id,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $index => $item) {
                $goodsReceipt->items()->create([
                    'description' => $item['description'],
                    'quantity_received' => $item['quantity_received'],
                    'unit' => $item['unit'],
                    'sort_order' => $index,
                ]);
            }

            ActivityLog::record('created', "Recorded Goods Receipt {$goodsReceipt->receipt_number}.", $goodsReceipt);

            return $goodsReceipt;
        });

        return redirect()->route('goods-receipts.show', $goodsReceipt)->with('flash', ['success' => 'Goods Receipt recorded.']);
    }

    public function show(GoodsReceipt $goodsReceipt): Response
    {
        $goodsReceipt->load('materialRequest:id,request_number', 'project:id,name', 'receiver:id,name', 'items');

        $activities = ActivityLog::where('subject_type', GoodsReceipt::class)
            ->where('subject_id', $goodsReceipt->id)
            ->with('user:id,name')
            ->latest()
            ->get();

        return Inertia::render('GoodsReceipts/Show', [
            'goodsReceipt' => $goodsReceipt,
            'activities' => $activities,
        ]);
    }
}
