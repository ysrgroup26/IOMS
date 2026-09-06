{{--
    v2.51.0 -- the shared document-control footer.

    Says what a controlled document footer has to say: which system
    produced it, when, and against which record. `$controlNote` lets a
    module add its own retention or distribution line without forking this
    partial.
--}}
@php $identity = $identity ?? []; @endphp

<table class="doc-footer">
    <tr>
        <td>
            {{ $identity['name'] ?? config('ioms.name') }} ·
            {{ $documentNumber ?? '' }}
            @if (! empty($controlNote)) · {{ $controlNote }} @endif
        </td>
        <td class="right">
            Generated {{ now()->format('d M Y H:i') }} · IOMS
        </td>
    </tr>
</table>
