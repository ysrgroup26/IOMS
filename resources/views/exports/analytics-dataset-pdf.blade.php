<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $dataset['label'] }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 16px; margin-bottom: 2px; color: #1e3a8a; }
        .subtitle { font-size: 11px; color: #6b7280; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #e5e7eb; padding: 6px 8px; text-align: left; }
        th { background-color: #eff6ff; color: #1e3a8a; }
        td.count { text-align: right; }
        .footer { margin-top: 20px; font-size: 8px; color: #9ca3af; text-align: right; }
    </style>
</head>
<body>
    <h1>{{ $dataset['label'] }}</h1>
    <div class="subtitle">
        {{ $companyName }} &middot; Generated {{ now()->format('d M Y H:i') }}
    </div>

    <table>
        <thead>
            <tr><th>Category</th><th>Count</th></tr>
        </thead>
        <tbody>
            @forelse($dataset['labels'] as $i => $label)
                <tr>
                    <td>{{ $label }}</td>
                    <td class="count">{{ $dataset['values'][$i] }}</td>
                </tr>
            @empty
                <tr><td colspan="2">No data.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">IOMS &middot; Report Center</div>
</body>
</html>
