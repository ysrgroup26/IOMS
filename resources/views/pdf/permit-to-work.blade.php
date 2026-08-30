<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $permit->ptw_number }}</title>
    <style>
        /* Mirrors pdf/material-request.blade.php's exact style choices --
           plain, traditional form styling suitable for wet-ink signatures
           and printing/sharing on-site, not a modern marketing layout. */
        body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #000; }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        .header-table td { vertical-align: top; padding: 0; }
        .company-name { font-size: 15px; font-weight: bold; }
        .company-sub { font-size: 10px; color: #333; }
        .doc-title { text-align: right; }
        .doc-title h1 { font-size: 16px; margin: 0 0 4px 0; text-transform: uppercase; letter-spacing: 1px; }
        .doc-title p { margin: 0; font-size: 11px; }
        hr { border: none; border-top: 2px solid #000; margin: 8px 0 14px 0; }

        .status-badge { display: inline-block; padding: 2px 8px; border: 1px solid #000; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; }

        .meta-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .meta-table td { padding: 3px 0; font-size: 11px; vertical-align: top; }
        .meta-label { width: 130px; color: #333; }
        .meta-colon { width: 12px; }

        .section-title { font-weight: bold; font-size: 11.5px; margin: 16px 0 6px 0; text-transform: uppercase; border-bottom: 1px solid #000; padding-bottom: 2px; }
        .body-text { font-size: 11px; white-space: pre-wrap; margin: 0; }

        table.gas-tests { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.gas-tests th, table.gas-tests td { border: 1px solid #000; padding: 4px 5px; font-size: 9.5px; text-align: left; }
        table.gas-tests th { background-color: #f0f0f0; text-transform: uppercase; font-size: 9px; }

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
    {{-- Same DocumentEngine template/branding pattern as pdf/material-request.blade.php -- $documentTemplate/$branding are null until a Company Admin creates a template for the 'permit_to_work' module key in Settings > Documents. --}}
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
                <h1>Permit To Work</h1>
                <p><strong>No:</strong> {{ $permit->ptw_number }}</p>
                <p><strong>Type:</strong> {{ ucwords(str_replace('_', ' ', $permit->permit_type)) }}</p>
                <p><span class="status-badge">{{ ucfirst($permit->status) }}</span></p>
            </td>
        </tr>
    </table>
    @if(($documentTemplate ?? null)?->header_text)
        <div class="template-header-text">{{ $documentTemplate->header_text }}</div>
    @endif
    <hr>

    <table class="meta-table">
        <tr>
            <td class="meta-label">Project / Site</td><td class="meta-colon">:</td><td>{{ $permit->project->name ?? '-' }}</td>
            <td class="meta-label">Location</td><td class="meta-colon">:</td><td>{{ $permit->location ?? '-' }}</td>
        </tr>
        <tr>
            <td class="meta-label">Start</td><td class="meta-colon">:</td><td>{{ $permit->start_datetime->format('d M Y H:i') }}</td>
            <td class="meta-label">End</td><td class="meta-colon">:</td><td>{{ $permit->end_datetime->format('d M Y H:i') }}</td>
        </tr>
        <tr>
            <td class="meta-label">Applicant</td><td class="meta-colon">:</td><td>{{ $permit->requester->name ?? '-' }}</td>
            <td class="meta-label">Required Qualification</td><td class="meta-colon">:</td><td>{{ $permit->required_qualification ?? '-' }}</td>
        </tr>
    </table>

    <div class="section-title">Work Description</div>
    <p class="body-text">{{ $permit->work_description }}</p>

    @if($permit->precautions)
        <div class="section-title">Safety Controls</div>
        <p class="body-text">{{ $permit->precautions }}</p>
    @endif

    @if($permit->riskAssessment || $permit->jsa)
        <div class="section-title">Related Documents</div>
        <table class="meta-table">
            {{-- v2.10.0 (PTW Document Polish pass, Phase 3D): previously
                 showed only the bare reference number ("HIRADC: 12"),
                 not richer already-loaded data. RiskAssessment.title /
                 JobSafetyAnalysis.job_title are already eager-loaded by
                 this method's own ->load() above (full models, no
                 column restriction) -- no new query, no new relation. --}}
            @if($permit->riskAssessment)
                <tr>
                    <td class="meta-label">HIRADC</td><td class="meta-colon">:</td>
                    <td>{{ $permit->riskAssessment->ra_number }} -- {{ $permit->riskAssessment->title }}</td>
                </tr>
            @endif
            @if($permit->jsa)
                <tr>
                    <td class="meta-label">JSA</td><td class="meta-colon">:</td>
                    <td>{{ $permit->jsa->jsa_number }} -- {{ $permit->jsa->job_title }}</td>
                </tr>
            @endif
        </table>
    @endif

    @if($permit->gasTests->count() > 0)
        <div class="section-title">Gas Test Readings</div>
        <table class="gas-tests">
            <thead>
                <tr><th>Time</th><th>Location</th><th>Stage</th><th>O2 %</th><th>LEL %</th><th>H2S ppm</th><th>CO ppm</th><th>Result</th><th>Tested By</th></tr>
            </thead>
            <tbody>
                @foreach($permit->gasTests as $g)
                    <tr>
                        <td>{{ $g->tested_at?->format('d M Y H:i') }}</td>
                        <td>{{ $g->location ?? '-' }}</td>
                        <td>{{ ucwords(str_replace('_', ' ', $g->stage ?? 'initial')) }}</td>
                        <td>{{ $g->o2_level ?? '-' }}</td>
                        <td>{{ $g->lel_level ?? '-' }}</td>
                        <td>{{ $g->h2s_level ?? '-' }}</td>
                        <td>{{ $g->co_level ?? '-' }}</td>
                        <td>{{ ucfirst($g->result) }}</td>
                        <td>{{ $g->tester->name ?? '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="section-title">Authorization</div>
    <table class="meta-table">
        <tr>
            <td class="meta-label">Requested By</td><td class="meta-colon">:</td><td>{{ $permit->requester->name ?? '-' }}</td>
            <td class="meta-label">HSE Approver</td><td class="meta-colon">:</td><td>{{ $permit->hseApprover->name ?? ($permit->status === 'submitted' ? 'Menunggu Persetujuan' : '-') }}</td>
        </tr>
        @if($permit->closer)
            <tr>
                <td class="meta-label">Closed By</td><td class="meta-colon">:</td><td>{{ $permit->closer->name }}</td>
                <td class="meta-label">Closed At</td><td class="meta-colon">:</td><td>{{ $permit->closed_at?->format('d M Y H:i') }}</td>
            </tr>
        @endif
    </table>

    {{-- v2.10.0 (PTW Document Polish pass, Phase 3D): same rejection-reason
         banner the browser Document view has shown since v2.6.0 -- was
         entirely missing from the PDF until this pass, a real
         browser/PDF parity gap this pass's own audit found. Never
         fabricated: shows a plain "no reason recorded" fallback for a
         permit rejected before v2.4.0 added the reason field. --}}
    @if($permit->status === 'rejected')
        <p class="body-text" style="margin-top: 8px; padding: 6px 8px; border: 1px solid #cc0000; color: #cc0000;">
            <strong>Alasan Penolakan:</strong> {{ $rejectionReason ?? 'Tidak ada alasan tercatat.' }}
        </p>
    @endif

    <table class="signatures">
        <tr>
            <td>
                <div class="sig-line">{{ $permit->requester->name ?? '' }}</div>
                <div class="sig-role">Applicant</div>
            </td>
            <td>
                <div class="sig-line">{{ $permit->hseApprover->name ?? '' }}</div>
                <div class="sig-role">HSE Approver</div>
            </td>
            <td>
                <div class="sig-line">&nbsp;</div>
                <div class="sig-role">Area Authority / PIC</div>
            </td>
        </tr>
    </table>

    <div class="footer-note">
        @if(($documentTemplate ?? null)?->footer_text)
            {{ $documentTemplate->footer_text }} &middot; {{ now()->format('d M Y H:i') }}
        @else
            Generated by {{ $branding['company_name'] ?? config('ioms.company') }} &middot; {{ now()->format('d M Y H:i') }} &middot; Document ID: {{ $permit->ptw_number }}
        @endif
    </div>
</body>
</html>
