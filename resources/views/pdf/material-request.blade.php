<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $materialRequest->request_number }}</title>
    <style>
        /* Deliberately plain, traditional form styling -- not a modern
           design. Serif-adjacent, black borders, dense table layout:
           the kind of document that looks at home next to existing
           paper forms, suitable for wet-ink signatures. */
        body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #000; }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        .header-table td { vertical-align: top; padding: 0; }
        .company-name { font-size: 15px; font-weight: bold; }
        .company-sub { font-size: 10px; color: #333; }
        .doc-title { text-align: right; }
        .doc-title h1 { font-size: 16px; margin: 0 0 4px 0; text-transform: uppercase; letter-spacing: 1px; }
        .doc-title p { margin: 0; font-size: 11px; }
        hr { border: none; border-top: 2px solid #000; margin: 8px 0 14px 0; }

        .meta-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .meta-table td { padding: 3px 0; font-size: 11px; }
        .meta-label { width: 130px; color: #333; }
        .meta-colon { width: 12px; }

        table.items { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        table.items th, table.items td { border: 1px solid #000; padding: 5px 6px; font-size: 10.5px; text-align: left; vertical-align: top; }
        table.items th { background-color: #f0f0f0; text-transform: uppercase; font-size: 9.5px; }
        table.items td.center { text-align: center; }
        table.items img { max-width: 60px; max-height: 60px; }

        .notes { margin-bottom: 24px; font-size: 11px; }
        .notes-label { font-weight: bold; margin-bottom: 3px; }

        table.signatures { width: 100%; border-collapse: collapse; margin-top: 30px; }
        table.signatures td { width: 33.3%; text-align: center; padding: 0 10px; font-size: 11px; vertical-align: top; }
        .sig-line { border-top: 1px solid #000; margin-top: 50px; padding-top: 4px; }
        .sig-role { color: #333; font-size: 10px; }

        .footer-note { margin-top: 24px; font-size: 9px; color: #666; text-align: center; }
        .header-logo { max-height: 44px; max-width: 60px; margin-bottom: 4px; }
        .template-header-text { font-size: 9px; color: #333; margin: -6px 0 8px 0; }
        .watermark { position: fixed; top: 40%; left: 18%; font-size: 56px; color: #f3f4f6; transform: rotate(-30deg); z-index: -1; }
    </style>
</head>
<body>
    {{-- Milestone 3 (Dynamic Document Engine, Task #66): $documentTemplate/
         $branding are resolved by App\Services\DocumentEngine and null
         until a Company Admin creates a template in Settings > Documents
         -- every ?? fallback below preserves this document's original,
         hardcoded appearance exactly, so nothing changes for a tenant
         that never touches Settings > Documents. --}}
    @if(($documentTemplate ?? null)?->show_watermark && $documentTemplate->watermark_text)
        <div class="watermark">{{ $documentTemplate->watermark_text }}</div>
    @endif

    <table class="header-table">
        <tr>
            <td style="width: 60%;">
                @if(($documentTemplate ?? null)?->show_logo && ($branding['logo_url'] ?? null))
                    <img class="header-logo" src="{{ $branding['logo_url'] }}" alt="Logo">
                @endif
                <div class="company-name">{{ $branding['company_name'] ?? $company->name ?? config('ioms.company') }}</div>
                @if($branding['address'] ?? null)
                    <div class="company-sub">{{ $branding['address'] }}</div>
                @endif
            </td>
            <td class="doc-title" style="width: 40%;">
                <h1>Material Request</h1>
                <p><strong>No:</strong> {{ $materialRequest->request_number }}</p>
                <p><strong>Date:</strong> {{ $materialRequest->request_date->format('d M Y') }}</p>
            </td>
        </tr>
    </table>
    @if(($documentTemplate ?? null)?->header_text)
        <div class="template-header-text">{{ $documentTemplate->header_text }}</div>
    @endif
    <hr>

    <table class="meta-table">
        <tr>
            <td class="meta-label">Requested By</td><td class="meta-colon">:</td><td>{{ $materialRequest->requester->name }}</td>
            <td class="meta-label">Department</td><td class="meta-colon">:</td><td>{{ $materialRequest->department->name ?? '-' }}</td>
        </tr>
        <tr>
            <td class="meta-label">Project</td><td class="meta-colon">:</td><td>{{ $materialRequest->project->name ?? '-' }}</td>
            <td class="meta-label">Status</td><td class="meta-colon">:</td><td>{{ ucfirst($materialRequest->status) }}</td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 4%;">No</th>
                <th style="width: 22%;">Item Name</th>
                <th style="width: 20%;">Specification</th>
                <th style="width: 9%;">Qty</th>
                <th style="width: 9%;">Unit</th>
                <th style="width: 14%;">Reference Image</th>
                <th style="width: 22%;">Remarks</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($materialRequest->items as $i => $item)
                <tr>
                    <td class="center">{{ $i + 1 }}</td>
                    <td>{{ $item->item_name }}</td>
                    <td>{{ $item->specification ?? '-' }}</td>
                    <td class="center">{{ rtrim(rtrim(number_format($item->quantity, 2), '0'), '.') }}</td>
                    <td class="center">{{ $item->unit }}</td>
                    <td class="center">
                        @if ($item->reference_image_path)
                            <img src="{{ public_path('storage/'.$item->reference_image_path) }}">
                        @else
                            -
                        @endif
                    </td>
                    <td>{{ $item->remarks ?? '-' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($materialRequest->notes)
        <div class="notes">
            <div class="notes-label">Notes:</div>
            <div>{{ $materialRequest->notes }}</div>
        </div>
    @endif

    <table class="signatures">
        <tr>
            <td>
                <div class="sig-line">{{ $materialRequest->requester->name }}</div>
                <div class="sig-role">Requested By</div>
            </td>
            <td>
                <div class="sig-line">&nbsp;</div>
                <div class="sig-role">Checked By</div>
            </td>
            <td>
                <div class="sig-line">&nbsp;</div>
                <div class="sig-role">Approved By</div>
            </td>
        </tr>
    </table>

    <div class="footer-note">
        @if(($documentTemplate ?? null)?->footer_text)
            {{ $documentTemplate->footer_text }} &middot; {{ now()->format('d M Y H:i') }}
        @else
            Generated by {{ $branding['company_name'] ?? config('ioms.company') }} &middot; {{ now()->format('d M Y H:i') }}
        @endif
    </div>
</body>
</html>
