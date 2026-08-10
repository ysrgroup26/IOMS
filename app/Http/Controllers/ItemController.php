<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Item;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Milestone 4, Acceleration Part 1A (Item Master). Reusable catalog for Warehouse/Maintenance/Project/Procurement -- see Item's own migration doc comment for the HSE-catalog scope boundary. */
class ItemController extends Controller
{
    public function index(Request $request): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');

        $items = Item::query()
            ->whereIn('company_id', $tenantCompanyIds)
            ->when($request->input('search'), fn ($q, $v) => $q->where('name', 'like', "%{$v}%")->orWhere('item_code', 'like', "%{$v}%"))
            ->when($request->input('type'), fn ($q, $v) => $q->where('type', $v))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Items/Index', [
            'items' => $items,
            'filters' => $request->only('search', 'type'),
            'types' => Item::TYPES,
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'can' => ['manage' => $request->user()->canManageWarehouse()],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageWarehouse(), 403);
        $tenantCompanyIds = Company::query()->pluck('id');

        $data = $request->validate([
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'type' => ['required', Rule::in(Item::TYPES)],
            'specification' => ['nullable', 'string', 'max:1000'],
            'unit' => ['required', 'string', 'max:20'],
            'brand' => ['nullable', 'string', 'max:100'],
            'min_stock' => ['required', 'integer', 'min:0'],
            'max_stock' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        if ($request->hasFile('attachment')) {
            $data['attachment_path'] = $request->file('attachment')->store('uploads/items', 'public');
        }
        unset($data['attachment']);

        $item = Item::create([...$data, 'item_code' => Item::generateCode()]);
        ActivityLog::record('created', "Item \"{$item->name}\" ({$item->item_code}) was added.", $item);

        return back()->with('success', 'Item added.');
    }

    public function update(Request $request, Item $item): RedirectResponse
    {
        abort_unless($request->user()->canManageWarehouse(), 403);
        $this->assertInCurrentTenant($item);
        $tenantCompanyIds = Company::query()->pluck('id');

        $data = $request->validate([
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'type' => ['required', Rule::in(Item::TYPES)],
            'specification' => ['nullable', 'string', 'max:1000'],
            'unit' => ['required', 'string', 'max:20'],
            'brand' => ['nullable', 'string', 'max:100'],
            'min_stock' => ['required', 'integer', 'min:0'],
            'max_stock' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        if ($request->hasFile('attachment')) {
            $data['attachment_path'] = $request->file('attachment')->store('uploads/items', 'public');
        }
        unset($data['attachment']);

        $item->update($data);
        ActivityLog::record('updated', "Item \"{$item->name}\" was updated.", $item);

        return back()->with('success', 'Item updated.');
    }

    public function destroy(Item $item, Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageWarehouse(), 403);
        $this->assertInCurrentTenant($item);

        if ($item->stockMovements()->exists()) {
            return back()->with('error', 'Cannot delete an item that has stock movement history.');
        }

        $name = $item->name;
        $item->delete();
        ActivityLog::record('deleted', "Item \"{$name}\" was removed.");

        return back()->with('success', 'Item removed.');
    }

    private function assertInCurrentTenant(Item $item): void
    {
        abort_unless(Company::query()->pluck('id')->contains($item->company_id), 404);
    }
}
