{{--
    v2.51.0 -- the shared IOMS document letterhead.

    Renders the TENANT's company identity, never the IOMS platform's and
    never a hardcoded company. `$identity` comes from
    DocumentEngine::identity(), which reads tenant-scoped company_settings,
    so a document can only ever carry the identity of the tenant that
    generated it.

    Expects:
      $identity  array from DocumentEngine::identity()
      $docTitle  string  e.g. "PURCHASE ORDER" / "SURAT PESANAN"
      $docSubtitle    optional string under the title
      $docRefs   optional array of label => value shown top-right
                 (document number, date, revision, page reference)

    Every field is optional except the company name: a customer who has
    filled in nothing but their name still gets a clean header rather than
    a block of empty lines.
--}}
@php
    $identity = $identity ?? [];
    $docRefs = $docRefs ?? [];
    $contactBits = array_filter([
        $identity['phone'] ?? null ? 'T '.$identity['phone'] : null,
        $identity['email'] ?? null,
        $identity['website'] ?? null,
    ]);
    $registryBits = array_filter([
        ($identity['tax_id'] ?? null) ? 'NPWP '.$identity['tax_id'] : null,
        ($identity['business_id'] ?? null) ? 'NIB '.$identity['business_id'] : null,
    ]);
@endphp

<table class="doc-letterhead">
    <tr>
        @if (! empty($identity['logo_url']))
            <td class="logo-cell">
                <img src="{{ $identity['logo_url'] }}" alt="">
            </td>
        @endif
        <td>
            <div class="doc-company-name">{{ $identity['name'] ?? config('ioms.name') }}</div>

            @if (! empty($identity['legal_name']) && $identity['legal_name'] !== ($identity['name'] ?? null))
                <div class="doc-company-legal">{{ $identity['legal_name'] }}</div>
            @endif

            <div class="doc-company-meta">
                @if (! empty($identity['address'])){{ $identity['address'] }}@endif
                @if (! empty($identity['locality']))<br>{{ $identity['locality'] }}@if (! empty($identity['country'])), {{ $identity['country'] }}@endif @endif
                @if ($contactBits)<br>{{ implode('  ·  ', $contactBits) }}@endif
                @if ($registryBits)<br>{{ implode('  ·  ', $registryBits) }}@endif
            </div>
        </td>

        @if ($docRefs)
            <td class="doc-ident-cell">
                <div class="doc-refs">
                    @foreach ($docRefs as $label => $value)
                        @continue(blank($value))
                        <div><span class="k">{{ $label }}</span> <span class="v">{{ $value }}</span></div>
                    @endforeach
                </div>
            </td>
        @endif
    </tr>
</table>
<div class="doc-rule"></div>

@if (! empty($docTitle))
    <table class="doc-title-band">
        <tr>
            <td>
                <div class="doc-title">{{ $docTitle }}</div>
                @if (! empty($docSubtitle))
                    <div class="doc-subtitle">{{ $docSubtitle }}</div>
                @endif
            </td>
        </tr>
    </table>
@endif
