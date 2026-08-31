<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $permit->ptw_number }}</title>
    <style>
        {{--
            v2.20.0 (PTW Experience & Visual Polish pass, Part 6/7). Kept
            the same Dompdf-safe constraints this file always had (no
            flexbox/grid -- Dompdf's CSS support is print-era, table-based
            layout only; Helvetica/Arial; explicit border-collapse) but
            reworked the visual hierarchy to match the browser Document
            view's own v2.20.0 pass: a dominant document-title block,
            numbered sections (01-05) with a light tinted header band
            instead of a bare bottom-border line, and Workforce/PIC/
            Requester shown as their own clearly labeled block instead of
            a plain ": value" table row. Same underlying data, same
            PdfGeneratorService/DocumentEngine pipeline, no new PDF engine.
        --}}
        body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #111827; }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        .header-table td { vertical-align: top; padding: 0; }
        .company-name { font-size: 15px; font-weight: bold; color: #0f172a; }
        .company-sub { font-size: 10px; color: #64748b; }
        .status-badge { display: inline-block; padding: 3px 9px; border: 1px solid #0f172a; border-radius: 3px; font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: bold; }

        /* Dominant document-title block -- mirrors Document.jsx's own
           "make the document title visually dominant" treatment. */
        .doc-title-block { border-bottom: 2px solid #0f172a; padding-bottom: 10px; margin: 14px 0 16px 0; }
        .doc-title-eyebrow { font-size: 9px; text-transform: uppercase; letter-spacing: 2px; color: #64748b; margin: 0 0 3px 0; }
        .doc-title-main { font-size: 22px; font-weight: bold; text-transform: uppercase; color: #0f172a; margin: 0; }
        .doc-title-number { font-size: 11px; color: #64748b; margin: 3px 0 0 0; }

        .meta-table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        .meta-table td { padding: 4px 0; font-size: 11px; vertical-align: top; }
        .meta-label { width: 130px; color: #64748b; font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; }
        .meta-colon { width: 12px; display: none; }

        /* Numbered section header -- a light tinted band (mirrors
           Document.jsx's `bg-graphite-50` header strip) instead of the
           previous bare bold-text-with-underline. */
        .section { border: 1px solid #e2e8f0; border-radius: 4px; margin-top: 12px; }
        .section-head { background-color: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 5px 10px; font-weight: bold; font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.5px; color: #1e293b; }
        .section-head .idx { color: #94a3b8; margin-right: 5px; }
        .section-body { padding: 10px 12px; }
        .field-label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; margin: 0 0 2px 0; }
        .field-value { font-size: 11px; color: #0f172a; margin: 0 0 8px 0; }
        .body-text { font-size: 11px; white-space: pre-wrap; margin: 0; color: #1e293b; }

        table.gas-tests { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.gas-tests th, table.gas-tests td { border: 1px solid #e2e8f0; padding: 4px 5px; font-size: 9.5px; text-align: left; }
        table.gas-tests th { background-color: #f8fafc; text-transform: uppercase; font-size: 9px; color: #64748b; }

        .doc-ref-box { border: 1px solid #e2e8f0; border-radius: 4px; padding: 6px 8px; margin-bottom: 6px; font-size: 10.5px; }
        .doc-ref-box .doc-ref-title { font-weight: bold; color: #0f172a; }
        .doc-ref-box .doc-ref-sub { color: #64748b; font-size: 9.5px; }

        table.signatures { width: 100%; border-collapse: collapse; margin-top: 30px; }
        table.signatures td { width: 33.3%; text-align: center; padding: 0 10px; font-size: 11px; vertical-align: top; }
        .sig-line { border-top: 1px solid #0f172a; margin-top: 50px; padding-top: 4px; }
        .sig-role { color: #64748b; font-size: 10px; }

        .footer-note { margin-top: 24px; font-size: 9px; color: #94a3b8; text-align: center; }
        .header-logo { max-height: 44px; max-width: 60px; margin-bottom: 4px; }
        .template-header-text { font-size: 9px; color: #64748b; margin: 4px 0 0 0; }
        .watermark { position: fixed; top: 40%; left: 18%; font-size: 56px; color: #f3f4f6; transform: rotate(-30deg); z-index: -1; }
        .rejection-box { margin-top: 8px; padding: 8px 10px; border: 1px solid #fca5a5; background-color: #fef2f2; color: #b91c1c; border-radius: 4px; font-size: 11px; }
    </style>
</head>
<body>
    {{-- Same DocumentEngine template/branding pattern as pdf/material-request.blade.php -- $documentTemplate/$branding are null until a Company Admin creates a template for the 'permit_to_work' module key in Settings > Documents. --}}
    @if(($documentTemplate ?? null)?->show_watermark && $documentTemplate->watermark_text)
        <div class="watermark">{{ $documentTemplate->watermark_text }}</div>
    @endif

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
                <span class="status-badge">{{ ucfirst(str_replace('_', ' ', $permit->status)) }}</span>
            </td>
        </tr>
    </table>
    @if(($documentTemplate ?? null)?->header_text)
        <div class="template-header-text">{{ $documentTemplate->header_text }}</div>
    @endif

    {{-- Dominant document-title block -- same visual role as
         Document.jsx's own title treatment. --}}
    <div class="doc-title-block">
        <p class="doc-title-eyebrow">Permit To Work</p>
        <p class="doc-title-main">{{ ucwords(str_replace('_', ' ', $permit->permit_type)) }}</p>
        <p class="doc-title-number">{{ $permit->ptw_number }}</p>
    </div>

    {{-- 01 -- Work Information --}}
    <div class="section">
        <div class="section-head"><span class="idx">01</span>Work Information</div>
        <div class="section-body">
            <table class="meta-table">
                <tr>
                    <td class="meta-label">Project / Site</td><td class="meta-colon"></td><td>{{ $permit->project->name ?? '-' }}</td>
                    <td class="meta-label">Location</td><td class="meta-colon"></td><td>{{ $permit->location ?? '-' }}</td>
                </tr>
                <tr>
                    <td class="meta-label">Valid From</td><td class="meta-colon"></td><td>{{ $permit->start_datetime->format('d M Y H:i') }}</td>
                    <td class="meta-label">Valid Until</td><td class="meta-colon"></td><td>{{ $permit->end_datetime->format('d M Y H:i') }}</td>
                </tr>
            </table>
            <p class="field-label" style="margin-top: 8px;">Work Description</p>
            <p class="body-text">{{ $permit->work_description }}</p>
        </div>
    </div>

    {{-- 02 -- Safety Controls --}}
    <div class="section">
        <div class="section-head"><span class="idx">02</span>Safety Controls</div>
        <div class="section-body">
            @if($permit->precautions)
                <p class="body-text">{{ $permit->precautions }}</p>
            @else
                <p class="body-text" style="font-style: italic; color: #94a3b8;">Tidak ada catatan pengendalian risiko tambahan.</p>
            @endif
            @if($permit->required_qualification)
                <p class="field-label" style="margin-top: 8px;">Required Qualification</p>
                <p class="field-value" style="margin-bottom: 0;">{{ $permit->required_qualification }}</p>
            @endif

            @if($permit->gasTests->count() > 0)
                <p class="field-label" style="margin-top: 8px;">Gas Test Readings</p>
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
        </div>
    </div>

    {{-- 03 -- Workforce (v2.17.0 data, v2.20.0 presentation) -- same
         real Employee/User references the browser Document view shows,
         never fabricated. --}}
    <div class="section">
        <div class="section-head"><span class="idx">03</span>Workforce</div>
        <div class="section-body">
            <table class="meta-table">
                <tr>
                    <td class="meta-label">PIC / Supervisor</td><td class="meta-colon"></td><td>{{ $permit->pic->full_name ?? '-' }}</td>
                    <td class="meta-label">Requester</td><td class="meta-colon"></td><td>{{ $permit->requester->name ?? '-' }}</td>
                </tr>
            </table>
            <p class="field-label" style="margin-top: 4px;">Personnel Involved &middot; Total {{ $permit->personnel->count() }} orang</p>
            @if($permit->personnel->count() > 0)
                <p class="body-text">{{ $permit->personnel->pluck('full_name')->join(', ') }}</p>
            @else
                <p class="body-text" style="font-style: italic; color: #94a3b8;">Belum ada personel yang dicatat.</p>
            @endif
        </div>
    </div>

    {{-- 04 -- Authorization --}}
    <div class="section">
        <div class="section-head"><span class="idx">04</span>Authorization</div>
        <div class="section-body">
            <table class="meta-table">
                <tr>
                    <td class="meta-label">Requester</td><td class="meta-colon"></td><td>{{ $permit->requester->name ?? '-' }}</td>
                    <td class="meta-label">HSE Approver</td><td class="meta-colon"></td><td>{{ $permit->hseApprover->name ?? ($permit->status === 'submitted' ? 'Menunggu Persetujuan' : '-') }}</td>
                </tr>
                @if($permit->closer)
                    <tr>
                        <td class="meta-label">Closed By</td><td class="meta-colon"></td><td>{{ $permit->closer->name }}</td>
                        <td class="meta-label">Closed At</td><td class="meta-colon"></td><td>{{ $permit->closed_at?->format('d M Y H:i') }}</td>
                    </tr>
                @endif
            </table>
            {{-- v2.10.0 (PTW Document Polish pass, Phase 3D): same rejection-reason
                 banner the browser Document view has shown since v2.6.0. Never
                 fabricated: shows a plain "no reason recorded" fallback for a
                 permit rejected before v2.4.0 added the reason field. --}}
            @if($permit->status === 'rejected')
                <div class="rejection-box">
                    <strong>Alasan Penolakan:</strong> {{ $rejectionReason ?? 'Tidak ada alasan tercatat.' }}
                </div>
            @endif
        </div>
    </div>

    {{-- 05 -- Supporting Documents -- only rendered when at least one
         linked document actually exists, same as the browser view. --}}
    @if($permit->riskAssessment || $permit->jsa)
        <div class="section">
            <div class="section-head"><span class="idx">05</span>Supporting Documents</div>
            <div class="section-body">
                @if($permit->riskAssessment)
                    <div class="doc-ref-box">
                        <div class="doc-ref-title">{{ $permit->riskAssessment->title }}</div>
                        <div class="doc-ref-sub">HIRADC &middot; {{ $permit->riskAssessment->ra_number }}</div>
                    </div>
                @endif
                @if($permit->jsa)
                    <div class="doc-ref-box">
                        <div class="doc-ref-title">{{ $permit->jsa->job_title }}</div>
                        <div class="doc-ref-sub">JSA &middot; {{ $permit->jsa->jsa_number }}</div>
                    </div>
                @endif
            </div>
        </div>
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
                {{-- v2.17.0: relabeled from "Area Authority / PIC" now
                     that PIC is a real, separately-shown field above
                     (Workforce section) -- see Document.jsx's own
                     comment on this same rename. --}}
                <div class="sig-role">Area Authority</div>
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
