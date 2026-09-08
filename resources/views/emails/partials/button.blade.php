{{--
    v2.58.0 -- the one call-to-action treatment.

    Table-wrapped rather than a styled anchor: Outlook (Word rendering
    engine) ignores padding on inline elements, so a "button" built from a
    padded <a> collapses to plain underlined text there. A single-cell
    table with the background on the CELL is the treatment that survives
    every client.

    Expects:
      $url    string
      $label  string
      $tone   optional 'brand' (default) | 'danger' | 'neutral'
--}}
@php
    $tone = $tone ?? 'brand';
    $background = match ($tone) {
        'danger' => '#b91c1c',
        'neutral' => '#334155',
        default => '#2166c4',
    };
@endphp

<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">
    <tr>
        <td align="center" bgcolor="{{ $background }}" style="background-color:{{ $background }}; border-radius:8px;">
            <a href="{{ $url }}"
               style="display:inline-block; padding:12px 26px; font-family:Segoe UI, Roboto, Helvetica, Arial, sans-serif; font-size:14px; font-weight:600; line-height:20px; color:#ffffff; text-decoration:none; border-radius:8px;">
                {{ $label }}
            </a>
        </td>
    </tr>
</table>
