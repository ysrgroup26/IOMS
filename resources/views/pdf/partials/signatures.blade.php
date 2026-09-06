{{--
    v2.51.0 -- the shared approval/signature block.

    An Indonesian industrial document is signed by several functions, and
    the boxes must exist on the printed page whether or not the system
    holds a name for each one yet -- an unsigned box is a physical
    instruction ("this still needs signing"), which is exactly what a
    controlled document should say.

    Expects $signatures: an array of
        ['role' => 'Dibuat Oleh', 'name' => ?string, 'position' => ?string, 'date' => ?string]

    Deliberately renders an EMPTY signing space when `name` is null rather
    than hiding the box or inventing an approver.
--}}
@php $signatures = $signatures ?? []; @endphp

@if ($signatures)
    <table class="sign avoid-break">
        <tr>
            @foreach ($signatures as $s)
                <td>
                    <div class="role">{{ $s['role'] ?? '' }}</div>
                    <div class="space"></div>
                    <div class="who">{{ $s['name'] ?? '' }}</div>
                    @if (! empty($s['position']))
                        <div class="when">{{ $s['position'] }}</div>
                    @endif
                    @if (! empty($s['date']))
                        <div class="when">{{ $s['date'] }}</div>
                    @endif
                </td>
            @endforeach
        </tr>
    </table>
@endif
