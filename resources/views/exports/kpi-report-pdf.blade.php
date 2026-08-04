<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>HSE KPI Report</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1f2937; }
        h1 { font-size: 16px; margin-bottom: 2px; color: #1e3a8a; }
        .subtitle { font-size: 11px; color: #6b7280; margin-bottom: 16px; }
        .dept-title {
            background-color: #2563eb; color: #fff; font-weight: bold;
            padding: 6px 8px; margin-top: 14px; font-size: 11px;
        }
        table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        th, td { border: 1px solid #e5e7eb; padding: 4px 6px; text-align: center; }
        th { background-color: #eff6ff; color: #1e3a8a; font-size: 9px; }
        td.name-col, th.name-col { text-align: left; }
        .negative { color: #b91c1c; font-weight: bold; }
        .footer { margin-top: 20px; font-size: 8px; color: #9ca3af; text-align: right; }
    </style>
</head>
<body>
    <h1>HSE KPI Report</h1>
    <div class="subtitle">
        Year {{ $year }}{{ $month ? ' &middot; Month '.$month : '' }} &middot; Generated {{ now()->format('d M Y H:i') }}
    </div>

    @foreach($report['departments'] as $group)
        <div class="dept-title">{{ $group['department_name'] }}</div>
        <table>
            <thead>
                <tr>
                    <th class="name-col">Employee</th>
                    <th>Emp. ID</th>
                    @foreach($report['categories'] as $category)
                        <th>{{ $category->short_label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse($group['rows'] as $row)
                    <tr>
                        <td class="name-col">{{ $row['employee']->full_name }}</td>
                        <td>{{ $row['employee']->employee_id }}</td>
                        @foreach($report['categories'] as $category)
                            <td class="{{ $category->is_negative && $row[$category->code] > 0 ? 'negative' : '' }}">
                                {{ $row[$category->code] }}
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ $report['categories']->count() + 2 }}">No employees in this department.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endforeach

    <div class="footer">{{ $companyName }}</div>
</body>
</html>
