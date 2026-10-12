<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $incident->incident_number }}</title>
    @include('pdf.partials.styles')
    <style>
        /*
            v2.112.0 -- Incident / Accident Report, document-only additions.
            Everything structural comes from the shared stylesheet; only the
            severity chip and the fact banner are specific to this report.
        */
        .chip { display: inline-block; padding: 1px 6px; border-radius: 2px; font-size: 7.5px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.4px; }
        .chip-critical { background: #fee2e2; color: #991b1b; }
        .chip-high     { background: #ffedd5; color: #9a3412; }
        .chip-medium   { background: #fef9c3; color: #854d0e; }
        .chip-low      { background: #dcfce7; color: #166534; }
        .chip-none     { background: #f1f5f9; color: #475569; }
        .prose { font-size: 9px; color: #334155; line-height: 1.45; white-space: pre-wrap; }
    </style>
</head>
<body>

{{--
    v2.112.0 -- Laporan Insiden / Incident & Accident Report.

    THE INITIAL REPORT, AND ONLY THAT. Incident Management owns what was
    reported; HSE Investigation is a separate record with its own number,
    its own workflow and its own document (ADR 036). Nothing here reaches
    into the investigation's fields -- a root cause printed on an initial
    report would make the two documents disagree about which one is the
    finding of record. Section 05 states only whether an investigation
    EXISTS and what its number is.

    A field the report does not hold prints as "-" rather than as a
    plausible value, and the injury block renders only when the record
    says somebody was hurt (`hasInjury()`), so a near miss does not print
    an empty medical section that reads as "no treatment given".
--}}

@php
    $severityClass = function (?string $value): string {
        return match (strtolower(trim((string) $value))) {
            'critical' => 'chip-critical',
            'high' => 'chip-high',
            'medium' => 'chip-medium',
            'low' => 'chip-low',
            default => 'chip-none',
        };
    };

    $label = fn (?string $token) => $token ? ucwords(str_replace('_', ' ', $token)) : null;
    $yesNo = fn (?bool $flag) => $flag === null ? '-' : ($flag ? 'Ya' : 'Tidak');

    $witnesses = is_array($incident->witnesses) ? $incident->witnesses : [];
    $investigation = $incident->relationLoaded('investigation') ? $incident->investigation : null;
@endphp

@include('pdf.partials.letterhead', [
    'identity' => $identity,
    'docTitle' => 'Incident & Accident Report / Laporan Insiden',
    'docSubtitle' => $label($incident->category),
    'docRefs' => [
        'No. Dokumen' => $incident->incident_number,
        'Tanggal Kejadian' => optional($incident->incident_date)->format('d M Y'),
        'Dicetak' => $generatedAt->format('d M Y H:i'),
    ],
])

<div class="section">
    <div class="section-title">01 &nbsp; Event Details</div>
    <table class="kv">
        <tr>
            <td class="k">Title</td><td class="v">{{ $incident->title ?: '-' }}</td>
            <td class="k">Severity</td>
            <td class="v"><span class="chip {{ $severityClass($incident->severity) }}">{{ strtoupper((string) ($incident->severity ?: 'N/A')) }}</span></td>
        </tr>
        <tr>
            <td class="k">Category</td><td class="v">{{ $label($incident->category) ?: '-' }}</td>
            <td class="k">Status</td><td class="v">{{ $label($incident->status) ?: '-' }}</td>
        </tr>
        <tr>
            <td class="k">Date &amp; Time</td>
            <td class="v">{{ optional($incident->incident_date)->format('d M Y') ?: '-' }}@if ($incident->incident_time), {{ $incident->incident_time }}@endif</td>
            <td class="k">Reported By</td><td class="v">{{ $incident->reporter->name ?? '-' }}</td>
        </tr>
        <tr>
            <td class="k">Location</td><td class="v">{{ $incident->location ?: '-' }}</td>
            <td class="k">Work Area</td><td class="v">{{ $incident->work_area ?: '-' }}</td>
        </tr>
        <tr>
            <td class="k">Operating Unit</td><td class="v">{{ $incident->company->name ?? '-' }}</td>
            <td class="k">Project</td><td class="v">{{ $incident->project->name ?? '-' }}</td>
        </tr>
        <tr>
            <td class="k">Work Related</td><td class="v">{{ $yesNo($incident->work_related) }}</td>
            <td class="k">Reportable to Authority</td><td class="v">{{ $yesNo($incident->reportable_to_authority) }}</td>
        </tr>
    </table>
</div>

@if ($incident->hasInjury())
    <div class="section">
        <div class="section-title">02 &nbsp; Injured Person</div>
        <table class="kv">
            <tr>
                <td class="k">Name</td>
                <td class="v">{{ $incident->injuredEmployee->full_name ?? $incident->injured_person_name ?: '-' }}</td>
                <td class="k">Person Type</td><td class="v">{{ $label($incident->injured_person_type) ?: '-' }}</td>
            </tr>
            <tr>
                <td class="k">Job Title</td><td class="v">{{ $incident->injured_person_job_title ?: '-' }}</td>
                <td class="k">ID Number</td><td class="v">{{ $incident->injured_person_id_number ?: '-' }}</td>
            </tr>
            <tr>
                <td class="k">Injury Type</td><td class="v">{{ $label($incident->injury_type) ?: '-' }}</td>
                <td class="k">Body Part</td><td class="v">{{ $label($incident->body_part) ?: '-' }}</td>
            </tr>
            <tr>
                <td class="k">Injury Severity</td><td class="v">{{ strtoupper((string) ($incident->injury_severity ?: '-')) }}</td>
                <td class="k">People Injured</td><td class="v">{{ $incident->people_injured ?? '-' }}</td>
            </tr>
            <tr>
                <td class="k">Immediate Treatment</td><td class="v">{{ $incident->immediate_treatment ?: '-' }}</td>
                <td class="k">Medical Facility</td>
                <td class="v">{{ $incident->medical_facility ?: '-' }}@if ($incident->referred_to_facility) (dirujuk)@endif</td>
            </tr>
        </table>
    </div>
@endif

<div class="section">
    <div class="section-title">03 &nbsp; Chronology &amp; Immediate Actions</div>

    @if ($incident->chronology)
        <p class="strong" style="font-size: 8.5px;">Kronologi</p>
        <div class="prose">{{ $incident->chronology }}</div>
    @endif

    @if ($incident->initial_circumstances)
        <p class="strong" style="font-size: 8.5px; margin-top: 6px;">Keadaan Awal</p>
        <div class="prose">{{ $incident->initial_circumstances }}</div>
    @endif

    @if ($incident->description)
        <p class="strong" style="font-size: 8.5px; margin-top: 6px;">Deskripsi</p>
        <div class="prose">{{ $incident->description }}</div>
    @endif

    @if ($incident->immediate_actions)
        <p class="strong" style="font-size: 8.5px; margin-top: 6px;">Tindakan Segera</p>
        <div class="prose">{{ $incident->immediate_actions }}</div>
    @endif

    @if (! $incident->chronology && ! $incident->initial_circumstances && ! $incident->description && ! $incident->immediate_actions)
        <p class="note">Laporan ini belum memuat kronologi maupun tindakan segera.</p>
    @endif
</div>

@if ($witnesses)
    <div class="section">
        <div class="section-title">04 &nbsp; Witnesses</div>
        <table class="data">
            <thead>
                <tr>
                    <th class="ctr" style="width: 26px;">No</th>
                    <th>Name</th>
                    <th style="width: 30%;">Contact</th>
                    <th style="width: 30%;">Statement</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($witnesses as $index => $witness)
                    <tr>
                        <td class="ctr">{{ $index + 1 }}</td>
                        <td>{{ is_array($witness) ? ($witness['name'] ?? '-') : $witness }}</td>
                        <td>{{ is_array($witness) ? ($witness['contact'] ?? $witness['phone'] ?? '') : '' }}</td>
                        <td>{{ is_array($witness) ? ($witness['statement'] ?? $witness['note'] ?? '') : '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

<div class="section">
    <div class="section-title">05 &nbsp; Investigation</div>
    @if ($investigation)
        <p class="note">
            Insiden ini memiliki investigasi tersendiri dengan nomor
            <span class="strong">{{ $investigation->investigation_number }}</span>
            (status: {{ $label($investigation->status) ?: '-' }}). Temuan dan penyebab dasar
            dilaporkan pada dokumen investigasi tersebut, bukan pada laporan awal ini.
        </p>
    @else
        <p class="note">Belum ada investigasi yang dibuka untuk insiden ini.</p>
    @endif
</div>

@include('pdf.partials.signatures', [
    'signatures' => [
        ['role' => 'Reported By', 'name' => $incident->reporter->name ?? null, 'position' => 'Pelapor'],
        ['role' => 'Verified By', 'name' => null, 'position' => 'HSE Officer'],
        ['role' => 'Acknowledged By', 'name' => null, 'position' => 'Site Management'],
    ],
])

@include('pdf.partials.footer', [
    'identity' => $identity,
    'documentNumber' => $incident->incident_number,
    'controlNote' => 'Dokumen terkendali · Laporan Awal Insiden',
])

</body>
</html>
