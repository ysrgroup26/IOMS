<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Employee Register</title>
    @include('pdf.partials.styles')
</head>
<body>

{{--
    v2.102.0 -- Employee Register / Daftar Karyawan.

    The printable counterpart of the Excel export, and deliberately the
    SAME query behind both: same tenant scoping, same company, department
    and search filters, same display order. A register that disagreed with
    the spreadsheet beside it would be worse than having none.

    It is a LIST document, not a per-person record. It answers "who is on
    the books, in which unit, doing what" -- the thing an auditor, a client
    or an HSE induction actually asks for on paper. A per-employee profile
    sheet is a different document and is not this one.

    The filters in force are printed in the header, because a filtered
    register that looks like a complete one is a document that misleads.
--}}

@include('pdf.partials.letterhead', [
    'identity' => $identity,
    'docTitle' => 'Employee Register / Daftar Karyawan',
    'docSubtitle' => $scopeLabel,
    'docRefs' => [
        'Tanggal' => $generatedAt->format('d M Y'),
        'Jumlah' => $employees->count().' karyawan',
    ],
])

@if ($employees->isEmpty())
    <p class="note">
        Tidak ada karyawan yang cocok dengan filter yang dipilih. Dokumen ini sengaja
        diterbitkan kosong agar tidak terbaca sebagai daftar lengkap.
    </p>
@else
    <table class="data">
        <thead>
            <tr>
                <th style="width:4%;" class="ctr">No</th>
                <th style="width:14%;">Employee ID</th>
                <th style="width:26%;">Full Name</th>
                <th style="width:18%;">Operating Unit</th>
                <th style="width:16%;">Department</th>
                <th style="width:14%;">Position</th>
                <th style="width:8%;" class="ctr">Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($employees as $index => $employee)
                <tr>
                    <td class="ctr">{{ $index + 1 }}</td>
                    <td>{{ $employee->employee_id ?: '-' }}</td>
                    <td class="strong">{{ $employee->full_name }}</td>
                    <td>{{ $employee->company?->name ?: '-' }}</td>
                    <td>{{ $employee->department?->name ?: '-' }}</td>
                    <td>{{ $employee->position?->name ?: '-' }}</td>
                    {{-- `status` is a stored identifier, so it is turned into
                         words here the same way the interface does. --}}
                    <td class="ctr">{{ ucwords(str_replace('_', ' ', (string) $employee->status)) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

<div class="space"></div>

<div class="avoid-break">
    @include('pdf.partials.signatures', ['signatures' => [
        ['role' => 'Disiapkan Oleh', 'name' => $preparedBy, 'date' => $generatedAt->format('d M Y')],
        ['role' => 'Diperiksa Oleh', 'name' => null],
    ]])
</div>

@include('pdf.partials.footer', [
    'identity' => $identity,
    'documentNumber' => 'REG-EMP-'.$generatedAt->format('Ymd-His'),
])

</body>
</html>
