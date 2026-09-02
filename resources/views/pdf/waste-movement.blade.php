<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Waste Movement #{{ $movement->id }}</title>
    <style>
        {{--
            v2.33.0 (Phase 4, Operational Document System). New document,
            same compact A4 density standard this session already
            established on pdf/permit-to-work.blade.php (v2.28.0) --
            same table-based Dompdf-safe layout (no flexbox/grid), same
            font-size/spacing scale, reused verbatim rather than
            reinvented, so every IOMS operational document reads as one
            consistent family. One page in the typical case (a handover
            manifest has far less content than a PTW).
        --}}
        body { font-family: Helvetica, Arial, sans-serif; font-size: 9.5px; color: #111827; }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 2px; }
        .header-table td { vertical-align: top; padding: 0; }
        .company-name { font-size: 13px; font-weight: bold; color: #0f172a; }
        .company-sub { font-size: 8.5px; color: #64748b; }
        .status-badge { display: inline-block; padding: 2px 8px; border: 1px solid #0f172a; border-radius: 3px; font-size: 8px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: bold; }

        .doc-title-block { border-bottom: 2px solid #0f172a; padding-bottom: 6px; margin: 8px 0 10px 0; }
        .doc-title-eyebrow { font-size: 7.5px; text-transform: uppercase; letter-spacing: 1.5px; color: #64748b; margin: 0 0 2px 0; }
        table.doc-title-row { width: 100%; border-collapse: collapse; }
        table.doc-title-row td { padding: 0; vertical-align: bottom; }
        .doc-title-main { font-size: 16px; font-weight: bold; text-transform: uppercase; color: #0f172a; margin: 0; }
        .doc-title-number { font-size: 9.5px; color: #64748b; margin: 0; text-align: right; }

        .meta-table { width: 100%; border-collapse: collapse; margin-bottom: 2px; }
        .meta-table td { padding: 2px 0; font-size: 9.5px; vertical-align: top; }
        .meta-label { width: 130px; color: #64748b; font-size: 7.5px; text-transform: uppercase; letter-spacing: 0.5px; }

        .section { border: 1px solid #e2e8f0; border-radius: 3px; margin-top: 6px; }
        .section-head { background-color: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 3px 8px; font-weight: bold; font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; color: #1e293b; }
        .section-head .idx { color: #94a3b8; margin-right: 4px; }
        .section-body { padding: 6px 8px; }
        .field-label { font-size: 7.5px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; margin: 0 0 1px 0; }
        .body-text { font-size: 9.5px; white-space: pre-wrap; margin: 0; color: #1e293b; line-height: 1.35; }

        .doc-ref-box { border: 1px solid #e2e8f0; border-radius: 3px; padding: 3px 6px; margin-bottom: 3px; font-size: 9px; }
        .doc-ref-box .doc-ref-title { font-weight: bold; color: #0f172a; }
        .doc-ref-box .doc-ref-sub { color: #64748b; font-size: 8px; }

        table.signatures { width: 100%; border-collapse: collapse; margin-top: 16px; }
        table.signatures td { width: 33.3%; text-align: center; padding: 0 8px; font-size: 9.5px; vertical-align: top; }
        .sig-line { border-top: 1px solid #0f172a; margin-top: 26px; padding-top: 3px; }
        .sig-role { color: #64748b; font-size: 8.5px; }

        .footer-note { margin-top: 10px; font-size: 7.5px; color: #94a3b8; text-align: center; }
        .header-logo { max-height: 44px; max-width: 60px; margin-bottom: 4px; }
        .template-header-text { font-size: 9px; color: #64748b; margin: 4px 0 0 0; }
    </style>
</head>
<body>
    <table class="header-table">
        <tr>
            <td style="width: 65%;">
                @if(($documentTemplate ?? null)?->show_logo && ($branding['logo_url'] ?? null))
                    <img class="header-logo" src="{{ $branding['logo_url'] }}" alt="Logo">
                @endif
                <div class="company-name">{{ $branding['company_name'] ?? $company->name ?? config('ioms.company') }}</div>
                @if($branding['address'] ?? null)
                    <div class="company-sub">{{ $branding['address'] }}</div>
                @endif
            </td>
            <td style="width: 35%; text-align: right;">
                <span class="status-badge">{{ ucfirst(str_replace('_', ' ', $movement->status)) }}</span>
            </td>
        </tr>
    </table>
    @if(($documentTemplate ?? null)?->header_text)
        <div class="template-header-text">{{ $documentTemplate->header_text }}</div>
    @endif

    <div class="doc-title-block">
        <p class="doc-title-eyebrow">Waste Handover Manifest</p>
        <table class="doc-title-row">
            <tr>
                <td class="doc-title-main">{{ $record->wasteType->name ?? 'Waste' }}</td>
                <td class="doc-title-number">{{ $movement->manifest_number ?? ('Movement #'.$movement->id) }}</td>
            </tr>
        </table>
    </div>

    {{-- 01 -- Waste Information (from the parent WasteRecord -- what was
         generated/collected, real fields only). --}}
    <div class="section">
        <div class="section-head"><span class="idx">01</span>Waste Information</div>
        <div class="section-body">
            <table class="meta-table">
                <tr>
                    <td class="meta-label">Waste Type</td><td>{{ $record->wasteType->name ?? '-' }} @if($record->wasteType?->waste_code) ({{ $record->wasteType->waste_code }}) @endif</td>
                    <td class="meta-label">Record Number</td><td>{{ $record->record_number ?? '-' }}</td>
                </tr>
                <tr>
                    <td class="meta-label">Quantity</td><td>{{ $record->quantity ?? '-' }} {{ $record->unit ?? '' }}</td>
                    <td class="meta-label">Storage Location</td><td>{{ $record->storageLocation->name ?? '-' }}</td>
                </tr>
                <tr>
                    <td class="meta-label">Project</td><td>{{ $record->project->name ?? '-' }}</td>
                    <td class="meta-label">Generated Date</td><td>{{ $record->generated_date?->format('d M Y') ?? '-' }}</td>
                </tr>
            </table>
        </div>
    </div>

    {{-- 02 -- Handover Details (the actual WasteMovement -- vendor,
         pickup/disposal, destination). --}}
    <div class="section">
        <div class="section-head"><span class="idx">02</span>Handover Details</div>
        <div class="section-body">
            <table class="meta-table">
                <tr>
                    <td class="meta-label">Vendor</td><td>{{ $movement->vendor->name ?? '-' }}</td>
                    <td class="meta-label">Destination</td><td>{{ $movement->destination ?? '-' }}</td>
                </tr>
                <tr>
                    <td class="meta-label">Pickup Date</td><td>{{ $movement->pickup_date?->format('d M Y') ?? '-' }}</td>
                    <td class="meta-label">Disposal Date</td><td>{{ $movement->disposal_date?->format('d M Y') ?? '-' }}</td>
                </tr>
                @if($movement->vendor?->address || $movement->vendor?->pic_name)
                <tr>
                    <td class="meta-label">Vendor Address</td><td>{{ $movement->vendor->address ?? '-' }}</td>
                    <td class="meta-label">Vendor PIC</td><td>{{ $movement->vendor->pic_name ?? '-' }} @if($movement->vendor?->pic_phone) ({{ $movement->vendor->pic_phone }}) @endif</td>
                </tr>
                @endif
            </table>
            @if($movement->notes)
                <p class="field-label" style="margin-top: 6px;">Notes</p>
                <p class="body-text">{{ $movement->notes }}</p>
            @endif
        </div>
    </div>

    {{-- 03 -- Supporting Documents -- only rendered when at least one
         attachment actually exists, same "real data only" rule every
         other document in this codebase follows. Lists the attachment
         itself (type/filename), not an embedded file. --}}
    @if($movement->documents->count() > 0)
        <div class="section">
            <div class="section-head"><span class="idx">03</span>Supporting Documents</div>
            <div class="section-body">
                @foreach($movement->documents as $doc)
                    <div class="doc-ref-box">
                        <div class="doc-ref-title">{{ $doc->original_name }}</div>
                        <div class="doc-ref-sub">{{ ucfirst(str_replace('_', ' ', $doc->document_type)) }} &middot; Uploaded by {{ $doc->uploader->name ?? '-' }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <table class="signatures">
        <tr>
            <td>
                <div class="sig-line">{{ $movement->creator->name ?? '' }}</div>
                <div class="sig-role">Recorded By ({{ $company->name ?? config('ioms.company') }})</div>
            </td>
            <td>
                <div class="sig-line">&nbsp;</div>
                <div class="sig-role">Vendor Representative</div>
            </td>
            <td>
                <div class="sig-line">&nbsp;</div>
                <div class="sig-role">Received By</div>
            </td>
        </tr>
    </table>

    <div class="footer-note">
        @if(($documentTemplate ?? null)?->footer_text)
            {{ $documentTemplate->footer_text }} &middot; {{ now()->format('d M Y H:i') }}
        @else
            Generated by {{ $branding['company_name'] ?? config('ioms.company') }} &middot; {{ now()->format('d M Y H:i') }} &middot; Movement ID: {{ $movement->id }}
        @endif
    </div>
</body>
</html>
