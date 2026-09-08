{{--
    v2.58.0 -- the shared key/value summary block.

    Every transactional message that states facts (an invoice, an order, an
    activated workspace) was drawing its own bordered table with its own
    padding and its own type sizes. One block now, so an invoice and an
    activation notice cannot disagree about how a labelled figure looks.

    Expects:
      $rows      array of ['label' => string, 'value' => string, 'strong' => bool?]
      $total     optional ['label' => string, 'value' => string] rendered emphasised
--}}
@php
    $rows = array_values(array_filter($rows ?? [], fn ($r) => filled($r['value'] ?? null)));
    $total = $total ?? null;
@endphp

@if ($rows || $total)
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e2e8f0; border-radius:8px; margin:0 0 20px;">
        <tr>
            <td style="padding:16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                    @foreach ($rows as $row)
                        <tr>
                            <td style="padding:4px 0; font-size:13px; line-height:20px; color:#64748b;">{{ $row['label'] }}</td>
                            <td style="padding:4px 0; font-size:13px; line-height:20px; color:#0f2747; text-align:right; {{ ($row['strong'] ?? false) ? 'font-weight:600;' : '' }}">
                                {{ $row['value'] }}
                            </td>
                        </tr>
                    @endforeach

                    @if ($total)
                        <tr>
                            <td style="padding:10px 0 4px; border-top:1px solid #e2e8f0; font-size:14px; line-height:20px; color:#0f2747; font-weight:600;">{{ $total['label'] }}</td>
                            <td style="padding:10px 0 4px; border-top:1px solid #e2e8f0; font-size:16px; line-height:22px; color:#0f2747; text-align:right; font-weight:700;">{{ $total['value'] }}</td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>
@endif
