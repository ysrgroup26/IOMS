{{--
    v2.130.0 -- THE DOCUMENT TYPEFACE, EMBEDDED IN THE PDF.

    Dompdf cannot reach the Google Fonts stylesheet the application uses,
    and must not try: a renderer that fetches a font over the network at
    render time is a renderer that produces a different document when the
    network is slow. So the PDF embeds STATIC INSTANCES of the same family
    from `resources/fonts`, committed to the repository.

    THEY ARE INSTANCES, NOT SEPARATE FONTS. Archivo ships from Google Fonts
    as one variable file with weight and width axes; dompdf cannot render a
    variable font (it would draw every weight as Regular), so each weight
    this product uses was instanced with fontTools and saved as its own TTF.
    The condensed one is the same family at 75% width -- the permit's type
    band, set as signage rather than as a heading.

    MEASURED, NOT ASSUMED. A probe rendered this exact declaration and
    `pdffonts` reported three embedded, subsetted CID TrueType faces
    (ArchivoRoman-CondensedExtraBold, -ExtraBold, -Bold) with the
    Indonesian text intact -- which is the only way to know, because a
    dompdf font that fails to load does not raise: it silently falls back
    to the default and the document merely looks ordinary.

    `resource_path()` is inside dompdf's chroot (`base_path()`), so the
    file:// URLs below resolve without opening anything else up.

    Licence: Archivo is SIL Open Font License 1.1 (Omnibus-Type), which
    permits embedding in documents. The licence travels with the fonts in
    resources/fonts/OFL.txt.
--}}
@php $iomsFontDir = str_replace('\\', '/', resource_path('fonts')); @endphp
<style>
    @font-face {
        font-family: "IOMS Display";
        font-weight: 700;
        font-style: normal;
        src: url("file://{{ $iomsFontDir }}/Archivo-Bold.ttf") format("truetype");
    }
    @font-face {
        font-family: "IOMS Display";
        font-weight: 800;
        font-style: normal;
        src: url("file://{{ $iomsFontDir }}/Archivo-ExtraBold.ttf") format("truetype");
    }
    @font-face {
        font-family: "IOMS Title";
        font-weight: 800;
        font-style: normal;
        src: url("file://{{ $iomsFontDir }}/ArchivoCondensed-ExtraBold.ttf") format("truetype");
    }
</style>
