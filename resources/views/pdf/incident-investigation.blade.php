<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $investigation->investigation_number }}</title>
    @include('pdf.partials.styles')
    <style>
        /* v2.112.0 -- Accident Investigation Report. Shared stylesheet
           plus one rule: the cause blocks are prose, not a table. */
        .prose { font-size: 9px; color: #334155; line-height: 1.45; white-space: pre-wrap; }
        .cause { border-left: 3px solid #2166c4; background: #f8fafc; padding: 5px 8px; margin-bottom: 6px; }
        .cause .t { font-size: 7.5px; text-transform: uppercase; letter-spacing: 0.6px; color: #64748b; }
    </style>
</head>
<body>

{{--
    v2.112.0 -- Laporan Investigasi / Accident Investigation Report.

    A SEPARATE record from the incident it investigates, with its own
    number and its own closure (ADR 036). The incident appears here only
    as the link back -- its number, title and date -- never its severity,
    category or chronology, because those are the initial report's
    findings of record and duplicating them here is how two documents
    start to disagree.

    CAPA is reported from the INVESTIGATION's own corrective actions
    (v2.73.0: the ones that address a cause hang off the analysis that
    identified it), not from the incident's containment actions.

    An unfinished investigation prints as unfinished. Nothing here fills
    a blank cause with a plausible sentence.
--}}

@php
    $label = fn (?string $token) => $token ? ucwords(str_replace('_', ' ', $token)) : null;
    $team = is_array($investigation->team) ? $investigation->team : [];
    $actions = $investigation->relationLoaded('correctiveActions') ? $investigation->correctiveActions : collect();

    $causes = array_filter([
        'Penyebab Langsung (Immediate Causes)' => $investigation->immediate_causes,
        'Penyebab Dasar (Basic Causes)' => $investigation->basic_causes,
        'Akar Masalah (Root Cause)' => $investigation->root_cause,
        'Faktor Kontribusi (Contributing Factors)' => $investigation->contributing_factors,
    ]);
@endphp

@include('pdf.partials.letterhead', [
    'identity' => $identity,
    'docTitle' => 'Accident Investigation Report / Laporan Investigasi',
    'docSubtitle' => $investigation->method ? 'Metode: '.strtoupper($investigation->method) : null,
    'docRefs' => [
        'No. Dokumen' => $investigation->investigation_number,
        'No. Insiden' => $investigation->incident->incident_number ?? null,
        'Dicetak' => $generatedAt->format('d M Y H:i'),
    ],
])

<div class="section">
    <div class="section-title">01 &nbsp; Investigation Details</div>
    <table class="kv">
        <tr>
            <td class="k">Incident</td>
            <td class="v">{{ $investigation->incident->incident_number ?? '-' }}@if ($investigation->incident?->title) · {{ $investigation->incident->title }}@endif</td>
            <td class="k">Status</td><td class="v">{{ $label($investigation->status) ?: '-' }}</td>
        </tr>
        <tr>
            <td class="k">Investigator</td><td class="v">{{ $investigation->investigator->name ?? '-' }}</td>
            <td class="k">Method</td><td class="v">{{ $investigation->method ? strtoupper($investigation->method) : '-' }}</td>
        </tr>
        <tr>
            <td class="k">Started</td><td class="v">{{ optional($investigation->started_at)->format('d M Y') ?: '-' }}</td>
            <td class="k">Target Completion</td><td class="v">{{ optional($investigation->target_completion_date)->format('d M Y') ?: '-' }}</td>
        </tr>
        <tr>
            <td class="k">Reviewed By</td>
            <td class="v">{{ $investigation->reviewer->name ?? '-' }}@if ($investigation->reviewed_at) ({{ $investigation->reviewed_at->format('d M Y') }})@endif</td>
            <td class="k">Closed By</td>
            <td class="v">{{ $investigation->closer->name ?? '-' }}@if ($investigation->closed_at) ({{ $investigation->closed_at->format('d M Y') }})@endif</td>
        </tr>
    </table>

    @if ($investigation->scope)
        <p class="strong" style="font-size: 8.5px; margin-top: 4px;">Ruang Lingkup</p>
        <div class="prose">{{ $investigation->scope }}</div>
    @endif
</div>

@if ($team)
    <div class="section">
        <div class="section-title">02 &nbsp; Investigation Team</div>
        <table class="data">
            <thead>
                <tr>
                    <th class="ctr" style="width: 26px;">No</th>
                    <th>Name</th>
                    <th style="width: 34%;">Role</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($team as $index => $member)
                    <tr>
                        <td class="ctr">{{ $index + 1 }}</td>
                        <td>{{ is_array($member) ? ($member['name'] ?? '-') : $member }}</td>
                        <td>{{ is_array($member) ? ($member['role'] ?? $member['position'] ?? '') : '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

@if ($investigation->detailed_chronology)
    <div class="section">
        <div class="section-title">03 &nbsp; Chronology</div>
        <div class="prose">{{ $investigation->detailed_chronology }}</div>
    </div>
@endif

<div class="section">
    <div class="section-title">04 &nbsp; Causes</div>
    @forelse ($causes as $heading => $body)
        <div class="cause avoid-break">
            <div class="t">{{ $heading }}</div>
            <div class="prose">{{ $body }}</div>
        </div>
    @empty
        <p class="note">
            Investigasi ini belum mencatat penyebab. Dokumen sengaja diterbitkan tanpa bagian
            penyebab agar tidak terbaca sebagai investigasi yang sudah menyimpulkan.
        </p>
    @endforelse
</div>

@if ($investigation->findings || $investigation->conclusion || $investigation->recommendations)
    <div class="section">
        <div class="section-title">05 &nbsp; Findings &amp; Recommendations</div>

        @if ($investigation->findings)
            <p class="strong" style="font-size: 8.5px;">Temuan</p>
            <div class="prose">{{ $investigation->findings }}</div>
        @endif

        @if ($investigation->conclusion)
            <p class="strong" style="font-size: 8.5px; margin-top: 6px;">Kesimpulan</p>
            <div class="prose">{{ $investigation->conclusion }}</div>
        @endif

        @if ($investigation->recommendations)
            <p class="strong" style="font-size: 8.5px; margin-top: 6px;">Rekomendasi</p>
            <div class="prose">{{ $investigation->recommendations }}</div>
        @endif
    </div>
@endif

<div class="section">
    <div class="section-title">06 &nbsp; Corrective Actions</div>
    @if ($actions->isEmpty())
        <p class="note">Belum ada tindakan perbaikan yang diterbitkan dari investigasi ini.</p>
    @else
        <table class="data">
            <thead>
                <tr>
                    <th class="ctr" style="width: 26px;">No</th>
                    <th>Action</th>
                    <th style="width: 110px;">Assignee</th>
                    <th class="ctr" style="width: 70px;">Due Date</th>
                    <th class="ctr" style="width: 70px;">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($actions as $index => $action)
                    <tr>
                        <td class="ctr">{{ $index + 1 }}</td>
                        <td>{{ $action->action }}</td>
                        <td>{{ $action->assignee->name ?? 'Belum ditugaskan' }}</td>
                        <td class="ctr">{{ optional($action->due_date)->format('d M Y') ?: '-' }}</td>
                        <td class="ctr">{{ strtoupper(str_replace('_', ' ', (string) $action->status)) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

@include('pdf.partials.signatures', [
    'signatures' => [
        ['role' => 'Investigated By', 'name' => $investigation->investigator->name ?? null, 'position' => 'Investigator'],
        ['role' => 'Reviewed By', 'name' => $investigation->reviewer->name ?? null, 'position' => 'HSE Manager'],
        ['role' => 'Approved By', 'name' => $investigation->closer->name ?? null, 'position' => 'Site Management'],
    ],
])

@include('pdf.partials.footer', [
    'identity' => $identity,
    'documentNumber' => $investigation->investigation_number,
    'controlNote' => 'Dokumen terkendali · Laporan Investigasi',
])

</body>
</html>
