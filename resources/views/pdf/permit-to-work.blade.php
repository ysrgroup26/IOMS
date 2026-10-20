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
        /* v2.28.0 (Product Experience Transformation, Deliverable B):
           this file was already Dompdf-safe and reasonably organized
           since v2.20.0, but was sized like a web page, not a physical
           A4 sheet -- 22px titles, 50px signature gaps, and 10-12px
           section padding compound fast across 5 sections and blow past
           one page for any permit with real safety-control text or a
           handful of gas tests. Every value below is tightened for real
           A4 density (target: 1 page typical, 2 pages max for a
           heavy permit) -- no section, field, or data point removed. */
        body { font-family: Helvetica, Arial, sans-serif; font-size: 9.5px; color: #111827; }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 2px; }
        .header-table td { vertical-align: top; padding: 0; }
        .company-name { font-size: 13px; font-weight: bold; color: #0f172a; }
        .company-sub { font-size: 8.5px; color: #64748b; }
        .status-badge { display: inline-block; padding: 2px 8px; border: 1px solid #0f172a; border-radius: 3px; font-size: 8px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: bold; }

        /* Dominant document-title block -- still the single largest
           element on the page, just no longer disproportionate to a
           compact A4 sheet. Permit Type and PTW Number now share one
           line instead of stacking, per this pass's own "side-by-side
           compact rows, not a tall stack" direction. */
        .doc-title-block { border-bottom: 2px solid #0f172a; padding-bottom: 6px; margin: 8px 0 10px 0; }
        .doc-title-eyebrow { font-size: 7.5px; text-transform: uppercase; letter-spacing: 1.5px; color: #64748b; margin: 0 0 2px 0; }
        table.doc-title-row { width: 100%; border-collapse: collapse; }
        table.doc-title-row td { padding: 0; vertical-align: bottom; }
        .doc-title-main { font-size: 16px; font-weight: bold; text-transform: uppercase; color: #0f172a; margin: 0; }
        .doc-title-number { font-size: 9.5px; color: #64748b; margin: 0; text-align: right; }

        .meta-table { width: 100%; border-collapse: collapse; margin-bottom: 2px; }
        .meta-table td { padding: 2px 0; font-size: 9.5px; vertical-align: top; }
        .meta-label { width: 110px; color: #64748b; font-size: 7.5px; text-transform: uppercase; letter-spacing: 0.5px; }
        .meta-colon { width: 12px; display: none; }

        /* Numbered section header -- unchanged concept, tightened band. */
        .section { border: 1px solid #e2e8f0; border-radius: 3px; margin-top: 6px; }
        .section-head { background-color: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 3px 8px; font-weight: bold; font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; color: #1e293b; }
        .section-head .idx { color: #94a3b8; margin-right: 4px; }
        .section-body { padding: 6px 8px; }
        .field-label { font-size: 7.5px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; margin: 0 0 1px 0; }
        .field-value { font-size: 9.5px; color: #0f172a; margin: 0 0 4px 0; }
        .body-text { font-size: 9.5px; white-space: pre-wrap; margin: 0; color: #1e293b; line-height: 1.35; }

        table.gas-tests { width: 100%; border-collapse: collapse; margin-top: 3px; }
        table.gas-tests th, table.gas-tests td { border: 1px solid #e2e8f0; padding: 2px 4px; font-size: 8px; text-align: left; }
        table.gas-tests th { background-color: #f8fafc; text-transform: uppercase; font-size: 7.5px; color: #64748b; }

        .doc-ref-box { border: 1px solid #e2e8f0; border-radius: 3px; padding: 3px 6px; margin-bottom: 3px; font-size: 9px; }
        .doc-ref-box .doc-ref-title { font-weight: bold; color: #0f172a; }
        .doc-ref-box .doc-ref-sub { color: #64748b; font-size: 8px; }

        table.signatures { width: 100%; border-collapse: collapse; margin-top: 16px; }
        /* v2.127.0: two columns, not three -- Applicant, then the
           two gates. Area Authority was removed (see the row itself). */
        table.signatures td { width: 33.3%; text-align: center; padding: 0 8px; font-size: 9.5px; vertical-align: top; }
        .sig-line { border-top: 1px solid #0f172a; margin-top: 26px; padding-top: 3px; }
        .sig-role { color: #64748b; font-size: 8.5px; }

        .footer-note { margin-top: 10px; font-size: 7.5px; color: #94a3b8; text-align: center; }
        .header-logo { max-height: 44px; max-width: 60px; margin-bottom: 4px; }
        .template-header-text { font-size: 9px; color: #64748b; margin: 4px 0 0 0; }
        .watermark { position: fixed; top: 40%; left: 18%; font-size: 56px; color: #f3f4f6; transform: rotate(-30deg); z-index: -1; }
        .rejection-box { margin-top: 8px; padding: 8px 10px; border: 1px solid #fca5a5; background-color: #fef2f2; color: #b91c1c; border-radius: 4px; font-size: 11px; }

        /* v2.42.0 -- ISSUED-DOCUMENT TREATMENT. The sheet was structurally
           correct but read as a form print-out: one hairline status chip on
           an all-white page, and three bare underscore lines for signatures.
           A permit is an ISSUED instrument -- an area authority glances at it
           on a wall or a clipboard and must read its state instantly, and an
           auditor must see who authorised it and when.

           Ink is spent only where it carries meaning: one navy identity band
           (this is an IOMS-issued permit), one semantic status band (what
           state it is in), and real bordered signature boxes (who signed).
           No full-page tint, no decorative rules -- a permit gets printed in
           volume, often mono, and toner is a real operational cost. All
           table/background-colour based: Dompdf has no flexbox or grid. */
        /* v2.126.0 -- the shared letterhead's classes, carried here
           because this view is deliberately self-contained (it predates
           partials/styles and its section/gas-test rules are its own).
           Values copied from partials/styles.blade.php so the permit's
           header is typographically identical to every other IOMS
           document's, which is the point of using the shared partial. */
        .doc-letterhead { width: 100%; border-bottom: 2px solid #0f2747; padding-bottom: 7px; margin-bottom: 2px; }
        .doc-letterhead .logo-cell { width: 68px; padding-right: 12px; }
        .doc-letterhead .logo-cell img { max-height: 58px; max-width: 66px; }
        .doc-company-name { font-size: 14px; font-weight: bold; color: #0f2747; letter-spacing: 0.2px; }
        .doc-company-legal { font-size: 8.5px; color: #64748b; margin-top: 1px; }
        .doc-company-meta { font-size: 8px; color: #475569; line-height: 1.5; margin-top: 3px; }
        .doc-ident-cell { width: 34%; text-align: right; }
        .doc-refs { font-size: 8.5px; color: #334155; text-align: right; line-height: 1.6; }
        .doc-refs .k { color: #94a3b8; }
        .doc-refs .v { font-weight: bold; color: #0f2747; }
        .doc-rule { height: 1px; background: #2166c4; margin-bottom: 8px; }

        /* Full-width status bar. The left rule is the semantic colour so the
           state survives a monochrome print as a position/weight cue, not a
           hue the printer will flatten to grey. */
        table.status-bar { width: 100%; border-collapse: collapse; margin: 0 0 8px 0; }
        table.status-bar td { padding: 5px 10px; font-size: 9px; vertical-align: middle; }
        .status-bar .sb-state { font-size: 12px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; }
        .status-bar .sb-meta { font-size: 8.5px; text-align: right; }

        /* Bordered authorisation boxes -- an auditable signing area rather
           than three floating rules. */
        table.signatures td { border: 1px solid #cbd5e1; }

        /* v2.126.0 -- the CSS stamps that lived here are GONE.
           Both the approval seal (a plain blue bordered box) and the
           upright ACTIVE/CLOSED plate were workarounds for a Dompdf
           limitation that does not exist: 3.1.5 renders `transform:
           rotate()` and renders SVG. They are now drawn by
           StampRenderer, once, for the screen and the sheet together.
           @see app/Services/Documents/StampRenderer.php */

        /* v2.73.0 -- PPE confirmation marks, drawn with borders instead of
           glyphs. A tick and a ballot box are non-Latin-1 characters, and
           using them made dompdf embed a whole font subset: the permit PDF
           measured 886 KB instead of 8 KB. Filled vs outlined says the same
           thing, costs nothing, and photocopies better. */
        .ppe-mark { display: inline-block; width: 6px; height: 6px; border: 1px solid #94a3b8; margin-right: 4px; }
        .ppe-mark-on { border-color: #047857; background-color: #047857; }
        .ppe-row { font-size: 8.5px; padding: 1px 0; color: #0f172a; }
        .ppe-legend { font-size: 7px; color: #64748b; margin-top: 3px; }
        .sig-head { background-color: #f1f5f9; border-bottom: 1px solid #cbd5e1; padding: 2.5px 6px; font-size: 7px; text-transform: uppercase; letter-spacing: 0.8px; color: #475569; font-weight: bold; text-align: left; }
        .sig-body { padding: 4px 6px 3px 6px; text-align: left; }
        .sig-name { font-size: 9.5px; font-weight: bold; color: #0f172a; min-height: 11px; }
        .sig-when { font-size: 7.5px; color: #64748b; }
        .sig-space { height: 30px; }
        .sig-rule { border-top: 1px solid #94a3b8; padding-top: 2px; font-size: 7px; color: #94a3b8; }

        /* Document-control strip: the audit metadata that makes a printed
           copy traceable back to the record it came from. */
        table.doc-control { width: 100%; border-collapse: collapse; margin-top: 10px; border-top: 1px solid #e2e8f0; }
        table.doc-control td { padding: 4px 0 0 0; font-size: 7px; color: #94a3b8; }
        table.doc-control .dc-label { text-transform: uppercase; letter-spacing: 0.6px; color: #cbd5e1; }
    </style>
</head>
<body>
    {{-- Same DocumentEngine template/branding pattern as pdf/material-request.blade.php -- $documentTemplate/$branding are null until a Company Admin creates a template for the 'permit_to_work' module key in Settings > Documents. --}}
    @if(($documentTemplate ?? null)?->show_watermark && $documentTemplate->watermark_text)
        <div class="watermark">{{ $documentTemplate->watermark_text }}</div>
    @endif

    {{-- v2.42.0: the tenant's identity and the permit's identity now sit in
         one navy band, so a printed permit is immediately recognisable as an
         issued instrument rather than a form dump. Tenant logo/name/address
         still come from DocumentEngine branding (tenant-scoped since
         v2.40.0), unchanged. --}}
    {{--
        v2.126.0 -- THE SHARED LETTERHEAD, WITH THE CUSTOMER'S ACTUAL LOGO.

        This view had its own navy identity band built from `branding()`,
        whose `logo_url` is an HTTP URL -- so rendering a permit made an
        outbound request to our own public host while Dompdf waited, and
        behind a firewall or a wrong APP_URL it drew nothing at all. That
        is the defect v2.112.0 fixed for every document that uses
        `partials/letterhead`, and the permit was not one of them: which is
        why the owner saw a company NAME where a logo belongs.

        The shared partial reads `identity()`, which carries `logo_path` --
        the file on local disk, null unless it is really there -- plus the
        legal name, the address, the locality, the contact line and the
        registry numbers, in the hierarchy every other IOMS document
        already uses. A permit now reads as one of the company's documents
        rather than as a differently-shaped one.

        No IOMS mark anywhere: the fallback is the customer's own company
        name, and never our logo. @see partials/letterhead
    --}}
    @include('pdf.partials.letterhead', [
        'identity' => array_merge($identity ?? [], [
            // The permit belongs to an operating unit, and the unit's own
            // name is a better answer than the product's when the tenant
            // has configured no company name at all.
            'name' => ($identity['name'] ?? null) ?: ($company->name ?? config('ioms.name')),
            // A template that switches the logo off still switches it off.
            'logo_path' => (($documentTemplate ?? null)?->show_logo === false) ? null : ($identity['logo_path'] ?? null),
            'logo_url' => (($documentTemplate ?? null)?->show_logo === false) ? null : ($identity['logo_url'] ?? null),
        ]),
    ])

    {{-- The permit's own identity, and its STANDING, on one line: the
         title and number left, the stamp right, where a reader's eye goes
         on a controlled document. The stamp is an SVG data URI built by
         StampRenderer -- the same bytes the screen renders -- and is null
         for any status that is not a standing, which is what keeps a
         six-colour stamp from existing. --}}
    <table style="width: 100%; border-collapse: collapse; margin: 2px 0 4px 0;">
        <tr>
            <td style="vertical-align: middle;">
                <div class="doc-title-eyebrow">Permit to Work</div>
                <div class="doc-title-main">{{ str_replace('_', ' ', $permit->permit_type) }}</div>
                <div class="doc-title-number">{{ $permit->ptw_number }}</div>
            </td>
            @if(! empty($stamps['standing']))
                {{-- Sized to sit INSIDE the type area. The first render
                     overran the rule on the right, which on a controlled
                     document reads as a printing fault rather than as a
                     stamp. --}}
                <td style="width: 128px; text-align: right; vertical-align: middle; padding-left: 8px;">
                    <img src="{{ $stamps['standing'] }}" style="width: 122px;" alt="{{ strtoupper($permit->status) }}">
                </td>
            @endif
        </tr>
    </table>
    <div style="border-bottom: 2px solid #0f172a; margin: 0 0 8px 0;"></div>

    @php
        // Semantic state presentation, derived from the permit's own status
        // only -- nothing here is decorative and nothing is invented.
        $statePresets = [
            'approved'  => ['label' => 'Approved', 'rule' => '#15803D', 'fill' => '#F0FDF4', 'ink' => '#166534'],
            'active'    => ['label' => 'Active: Work In Progress', 'rule' => '#15803D', 'fill' => '#F0FDF4', 'ink' => '#166534'],
            // v2.127.0 -- two waits, named separately. "Awaiting Approval"
            // on a permit HSE has not yet seen told a reader the wrong
            // thing about which desk it is sitting on.
            'submitted' => ['label' => 'Awaiting HSE Verification', 'rule' => '#B45309', 'fill' => '#FFFBEB', 'ink' => '#92400E'],
            'verified'  => ['label' => 'Verified: Awaiting Final Approval', 'rule' => '#B45309', 'fill' => '#FFFBEB', 'ink' => '#92400E'],
            'rejected'  => ['label' => 'Rejected', 'rule' => '#B91C1C', 'fill' => '#FEF2F2', 'ink' => '#991B1B'],
            'cancelled' => ['label' => 'Cancelled', 'rule' => '#B91C1C', 'fill' => '#FEF2F2', 'ink' => '#991B1B'],
            'closed'    => ['label' => 'Closed', 'rule' => '#475569', 'fill' => '#F8FAFC', 'ink' => '#334155'],
            'draft'     => ['label' => 'Draft: Not Valid For Work', 'rule' => '#94A3B8', 'fill' => '#F8FAFC', 'ink' => '#475569'],
        ];
        $state = $statePresets[$permit->status] ?? [
            'label' => ucfirst(str_replace('_', ' ', $permit->status)),
            'rule' => '#475569', 'fill' => '#F8FAFC', 'ink' => '#334155',
        ];
        $authorisedBy = $permit->hseApprover->name ?? null;
    @endphp

    {{-- The left rule carries the semantic colour as POSITION and weight, so
         the state still reads on the monochrome prints these are usually
         issued as, where a hue alone would flatten to grey. --}}
    <table class="status-bar">
        <tr>
            <td style="width: 5px; padding: 0; background-color: {{ $state['rule'] }};"></td>
            <td style="background-color: {{ $state['fill'] }}; border-top: 1px solid {{ $state['rule'] }}; border-bottom: 1px solid {{ $state['rule'] }};">
                <span class="sb-state" style="color: {{ $state['ink'] }};">{{ $state['label'] }}</span>
            </td>
            <td class="sb-meta" style="background-color: {{ $state['fill'] }}; border-top: 1px solid {{ $state['rule'] }}; border-bottom: 1px solid {{ $state['rule'] }}; color: {{ $state['ink'] }};">
                @if(in_array($permit->status, ['approved', 'active', 'closed'], true) && $authorisedBy)
                    Authorised by {{ $authorisedBy }}
                @elseif($permit->status === 'submitted')
                    Not valid for work until verified and approved
                @elseif($permit->status === 'verified')
                    Verified by {{ $verification['approver'] ?? 'HSE' }}. Not valid for work until approved
                @elseif($permit->status === 'rejected')
                    Not authorised
                @endif
            </td>
        </tr>
    </table>
    @if(($documentTemplate ?? null)?->header_text)
        <div class="template-header-text">{{ $documentTemplate->header_text }}</div>
    @endif

    {{-- v2.126.0: the dominant title block that stood here is GONE.
         It rendered "PERMIT TO WORK / HOT WORK / PTW-2026-00001" a SECOND
         time, a few millimetres under the identical block the letterhead
         group above already draws -- obvious the moment the rendered sheet
         was looked at, invisible while only the template was read. One
         document, one title. --}}

    {{-- 01 -- Work Information --}}
    <div class="section">
        <div class="section-head"><span class="idx">01</span>Work Information</div>
        <div class="section-body">
            <table class="meta-table">
                <tr>
                    {{-- v2.52.0: work identity, then work location -- two
                         different facts. Falls back to the free-text job name so a
                         permit for real work never prints as having no project. --}}
                    <td class="meta-label">Project / Job</td><td class="meta-colon"></td><td>{{ $permit->workIdentity() ?? '-' }}</td>
                    <td class="meta-label">Work Location</td><td class="meta-colon"></td><td>{{ $permit->location ?? '-' }}</td>
                </tr>
                <tr>
                    {{-- v2.37.0 (Master Audit): CONFIRMED defect. These
                         printed the raw UTC wall clock while the web UI
                         printed the viewer's browser timezone -- an exact
                         8h disagreement for a UTC+8 user, i.e. the
                         PDF-vs-UI mismatch reported from production. Both
                         sides now resolve against the one configured
                         display timezone. Stored values are untouched. --}}
                    <td class="meta-label">Valid From</td><td class="meta-colon"></td><td>{{ $permit->start_datetime->timezone(config('ioms.display_timezone'))->format('d M Y H:i') }}</td>
                    <td class="meta-label">Valid Until</td><td class="meta-colon"></td><td>{{ $permit->end_datetime->timezone(config('ioms.display_timezone'))->format('d M Y H:i') }}</td>
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

    {{-- v2.73.0 -- HAZARDS AND REQUIRED PPE, ON PAPER.

         A printed permit authorises hazardous work at a gate, in poor
         light, often photocopied. Before this release it named neither the
         hazards nor the protection: both were inside one free-text
         "precautions" line, which cannot be checked item by item.

         THE CONFIRMED MARK IS A CHARACTER, NOT A COLOUR. A green tick
         survives neither a mono photocopier nor a phone photo of a page
         taped to a bulkhead; a tick and an empty box survive both. Colour
         is layered on top for a screen-read PDF, never relied on.

         Rendered only from \$ppe, which the controller resolves from the
         ids this permit actually stored -- never from the live PPE master,
         so deactivating a type next year cannot rewrite what this permit
         said. --}}
    {{-- Block form, not the single-expression directive form:
         this Blade version compiles that one to an unterminated PHP open
         tag, which swallows every directive after it and took PDF
         generation down with a 500.

         AND NOTE, FOR ANYONE EDITING A COMMENT IN A BLADE FILE: Blade
         compiles directives BEFORE it strips comments, so writing a
         directive name with its leading at-sign inside a comment causes
         that directive to be COMPILED -- turning the comment into real
         PHP and silently deleting everything after it. This section
         disappeared from the PDF exactly that way once already, while
         the template itself read perfectly. Describe directives in
         words here; never spell one out. --}}
    @php
        $ptwPpe = $ppe ?? null;
    @endphp
    @if(($ptwPpe['required'] ?? []) || ($ptwPpe['additional'] ?? []) || ($permit->hazards ?? []))
        <div class="section">
            <div class="section-head"><span class="idx">03</span>Hazards &amp; Required PPE</div>
            <div class="section-body">
                @if($permit->hazards ?? [])
                    <table class="meta-table">
                        <tr>
                            <td class="meta-label">Work Hazards</td><td class="meta-colon"></td>
                            <td>{{ implode(' · ', $permit->hazards) }}</td>
                        </tr>
                    </table>
                @endif

                <table style="width:100%; margin-top:4px;">
                    <tr>
                        @php
                            $confirmedIds = collect($ptwPpe['confirmed'] ?? [])->pluck('id')->all();
                            $ppeRows = collect($ptwPpe['required'] ?? [])
                                ->map(fn ($t) => ['name' => $t['name'], 'confirmed' => in_array($t['id'], $confirmedIds, true)])
                                ->concat(collect($ptwPpe['additional'] ?? [])->map(fn ($n) => ['name' => $n, 'confirmed' => false]))
                                ->values();
                            // Two columns, split down the middle rather than
                            // alternating, so the list reads top-to-bottom in
                            // each column the way a checklist is read.
                            $half = (int) ceil($ppeRows->count() / 2);
                        @endphp
                        @foreach([$ppeRows->slice(0, $half), $ppeRows->slice($half)] as $column)
                            <td style="width:50%; vertical-align:top;">
                                @foreach($column as $row)
                                    <div class="ppe-row">
                                        <span class="ppe-mark {{ $row['confirmed'] ? 'ppe-mark-on' : '' }}"></span>{{ $row['name'] }}
                                    </div>
                                @endforeach
                            </td>
                        @endforeach
                    </tr>
                </table>

                <div class="ppe-legend">
                    <span class="ppe-mark ppe-mark-on"></span>dikonfirmasi tersedia saat persetujuan HSE
                    &nbsp;&nbsp;<span class="ppe-mark"></span>belum dikonfirmasi
                </div>
            </div>
        </div>
    @endif

    {{-- 03 -- Workforce (v2.17.0 data, v2.20.0 presentation) -- same
         real Employee/User references the browser Document view shows,
         never fabricated. --}}
    <div class="section">
        <div class="section-head"><span class="idx">04</span>Work Personnel</div>
        <div class="section-body">
            <table class="meta-table">
                <tr>
                    <td class="meta-label">Penanggung Jawab</td><td class="meta-colon"></td><td>{{ $permit->picDisplayName() ?? '-' }}</td>
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
        <div class="section-head"><span class="idx">05</span>Authorization</div>
        <div class="section-body">
            <table class="meta-table">
                <tr>
                    <td class="meta-label">Requester</td><td class="meta-colon"></td><td>{{ $permit->requester->name ?? '-' }}</td>
                    {{-- v2.127.0: the two gates, each naming its own stage's
                         wait. "Menunggu Persetujuan" on a permit HSE has not
                         yet seen named the wrong desk. --}}
                    <td class="meta-label">HSE Verified By</td><td class="meta-colon"></td><td>{{ $permit->hseVerifier->name ?? ($permit->status === 'submitted' ? 'Menunggu Verifikasi HSE' : '-') }}</td>
                    <td class="meta-label">Approved By</td><td class="meta-colon"></td><td>{{ $permit->hseApprover->name ?? ($permit->status === 'verified' ? 'Menunggu Persetujuan Akhir' : '-') }}</td>
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
            <div class="section-head"><span class="idx">06</span>Supporting Documents</div>
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

    {{-- v2.42.0 -- AUTHORISATION BLOCK. Was three bare underscore rules with
         a name floated above them, which told an auditor who was *expected*
         to sign but never whether they actually had. Each role is now its
         own bordered box carrying the recorded name and the recorded
         timestamp where one exists, and an explicit ruled space for the wet
         signature where it does not. Nothing is asserted that the record
         does not hold: an unapproved permit shows an empty box, never a
         pre-filled name. --}}
    <table class="signatures">
        <tr>
            <td>
                <div class="sig-head">Applicant / Requester</div>
                <div class="sig-body">
                    <div class="sig-name">{{ $permit->requester->name ?? '' }}</div>
                    <div class="sig-when">{{ $permit->created_at?->timezone(config('ioms.display_timezone'))->format('d M Y H:i') ?? '' }}</div>
                    <div class="sig-space"></div>
                    <div class="sig-rule">Signature</div>
                </div>
            </td>
            <td>
                {{-- v2.127.0 -- THE VERIFICATION, which is a different act by
                     a different person. A permit now passes two gates: HSE
                     verifies, then an approver signs, and the sheet shows
                     both or neither -- never one standing in for the other.
                     Absent entirely until the verification is recorded,
                     because `$verification` is null until then and there is
                     no boolean here to get it wrong. --}}
                <div class="sig-head">HSE Verified By</div>
                <div class="sig-body">
                    @if($verification ?? null)
                        @if(! empty($stamps['verification']))
                            <img src="{{ $stamps['verification'] }}" style="width: 128px;" alt="Verified by {{ $verification['approver'] }}">
                        @else
                            <div class="sig-name">{{ $verification['approver'] }}</div>
                            @if($verification['role'])<div class="sig-when">{{ $verification['role'] }}</div>@endif
                        @endif
                    @else
                        <div class="sig-name">{{ $permit->hseVerifier->name ?? '' }}</div>
                        <div class="sig-when">@if($permit->status === 'submitted')Awaiting verification @endif</div>
                        <div class="sig-space"></div>
                        <div class="sig-rule">Signature</div>
                    @endif
                </div>
            </td>
            <td>
                <div class="sig-head">Approved By</div>
                <div class="sig-body">
                    @if($authorization ?? null)
                        {{-- v2.72.0: an authorised permit shows the seal where the
                             blank signature rule would otherwise be -- the same
                             seal, in the same place, as the on-screen document,
                             so paper and screen can never disagree about whether
                             the work was authorised. An unauthorised permit is
                             unchanged: still an empty box, never a pre-filled
                             name. --}}
                        {{-- v2.126.0: the real seal. This was a plain
                             bordered box with the word "Approved" in it,
                             because v2.125.0 believed Dompdf could render
                             neither a rotation nor an ink texture and never
                             checked. It renders both. The image is the SAME
                             SVG the screen shows, built by StampRenderer
                             from the stored action record, so paper and
                             screen cannot differ -- and it is absent
                             entirely when there is no record, which is why
                             there is no boolean here. --}}
                        @if(! empty($stamps['approval']))
                            <img src="{{ $stamps['approval'] }}" style="width: 128px;" alt="Approved by {{ $authorization['approver'] }}">
                        @else
                            <div class="sig-name">{{ $authorization['approver'] }}</div>
                            @if($authorization['role'])<div class="sig-when">{{ $authorization['role'] }}</div>@endif
                        @endif
                    @else
                        <div class="sig-name">{{ $permit->hseApprover->name ?? '' }}</div>
                        <div class="sig-when">@if($permit->status === 'submitted')Pending @endif</div>
                        <div class="sig-space"></div>
                        <div class="sig-rule">Signature</div>
                    @endif
                </div>
            </td>
            {{-- v2.127.0: the Area Authority column is GONE from this row.
                 `area_authority_id` has had no writer anywhere in this
                 codebase since it was added -- the v2.72.0 comment above
                 already said it "will currently ALWAYS render blank" -- and
                 with verification and approval now needing a column each,
                 four columns on A4 crushed all three seals to illegibility.
                 A blank box for an unwired field is not worth the width of
                 a seal that carries a signature. --}}
        </tr>
    </table>

    {{-- Document control: what makes a PRINTED copy traceable back to the
         record it came from, which is the first thing an auditor asks of a
         permit found on a wall. --}}
    <table class="doc-control">
        <tr>
            <td style="width: 34%;">
                <span class="dc-label">Document ID</span><br>{{ $permit->ptw_number }}
            </td>
            <td style="width: 33%;">
                <span class="dc-label">Status At Print</span><br>{{ $state['label'] }}
            </td>
            <td style="width: 33%; text-align: right;">
                <span class="dc-label">Printed</span><br>{{ now()->timezone(config('ioms.display_timezone'))->format('d M Y H:i') }}
            </td>
        </tr>
    </table>

    <div class="footer-note">
        @if(($documentTemplate ?? null)?->footer_text)
            {{ $documentTemplate->footer_text }} &middot; {{ now()->format('d M Y H:i') }}
        @else
            Generated by {{ $branding['company_name'] ?? $company->name ?? config('ioms.name') }} &middot; {{ now()->format('d M Y H:i') }} &middot; Document ID: {{ $permit->ptw_number }}
        @endif
    </div>
</body>
</html>
