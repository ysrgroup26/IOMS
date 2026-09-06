{{--
    v2.51.0 -- the shared IOMS document stylesheet.

    ONE stylesheet for every generated document, so a purchase order and a
    permit look like they came from the same company rather than from two
    developers a year apart.

    DOMPDF CONSTRAINTS, which shape everything below:
      * no flexbox, no grid, no CSS variables -- layout is tables
      * no `position: fixed` except inside @page margin boxes
      * limited `calc()`; widths are percentages
      * web fonts are unreliable; DejaVu Sans is built in and handles the
        Latin-1 range these documents need
    Anything here that looks dated is dated on purpose: it is what actually
    renders identically in a PDF viewer and on a printer.

    Print target is A4 portrait with 12mm side margins -- wide enough for a
    filed, hole-punched copy without wasting the content column.
--}}
<style>
    @page {
        margin: 14mm 12mm 16mm 12mm;
    }

    * { box-sizing: border-box; }

    body {
        font-family: "DejaVu Sans", sans-serif;
        font-size: 9.5px;
        line-height: 1.45;
        color: #1f2937;
        margin: 0;
        padding: 0;
    }

    table { border-collapse: collapse; width: 100%; }
    td, th { vertical-align: top; }
    p { margin: 0 0 4px; }

    /* ---------------- Letterhead ---------------- */
    .doc-letterhead { width: 100%; border-bottom: 2px solid #0f2747; padding-bottom: 7px; margin-bottom: 2px; }
    .doc-letterhead .logo-cell { width: 68px; padding-right: 12px; }
    .doc-letterhead .logo-cell img { max-height: 58px; max-width: 66px; }
    .doc-company-name { font-size: 14px; font-weight: bold; color: #0f2747; letter-spacing: 0.2px; }
    .doc-company-legal { font-size: 8.5px; color: #64748b; margin-top: 1px; }
    .doc-company-meta { font-size: 8px; color: #475569; line-height: 1.5; margin-top: 3px; }
    .doc-ident-cell { width: 34%; text-align: right; }

    /* A hairline second rule under the main one -- the standard controlled
       document look, and it costs nothing in print. */
    .doc-rule { height: 1px; background: #2166c4; margin-bottom: 10px; }

    /* ---------------- Document title block ---------------- */
    .doc-title-band { width: 100%; margin-bottom: 10px; }
    .doc-title {
        font-size: 13px; font-weight: bold; color: #0f2747;
        text-transform: uppercase; letter-spacing: 0.6px;
    }
    .doc-subtitle { font-size: 8.5px; color: #64748b; margin-top: 1px; }
    .doc-refs { font-size: 8.5px; color: #334155; text-align: right; line-height: 1.6; }
    .doc-refs .k { color: #94a3b8; }
    .doc-refs .v { font-weight: bold; color: #0f2747; }

    /* ---------------- Sections ---------------- */
    .section { margin-bottom: 10px; }
    .section-title {
        font-size: 8.5px; font-weight: bold; text-transform: uppercase;
        letter-spacing: 0.8px; color: #2166c4;
        border-bottom: 1px solid #dbe3ec; padding-bottom: 3px; margin-bottom: 5px;
    }

    /* Label/value pairs. A table, not floats -- dompdf floats are fragile. */
    .kv td { padding: 2.5px 0; font-size: 9px; }
    .kv .k { width: 30%; color: #64748b; }
    .kv .v { color: #111827; font-weight: bold; }

    /* ---------------- Data tables ---------------- */
    .data { border: 1px solid #cbd5e1; }
    .data th {
        background: #eef2f7; color: #0f2747; font-size: 8px; font-weight: bold;
        text-transform: uppercase; letter-spacing: 0.4px;
        padding: 5px 6px; border-bottom: 1px solid #cbd5e1; text-align: left;
    }
    .data td { padding: 5px 6px; font-size: 9px; border-bottom: 1px solid #e2e8f0; }
    .data tr:last-child td { border-bottom: none; }
    .data .num { text-align: right; }
    .data .ctr { text-align: center; }
    .data tfoot td {
        background: #f8fafc; font-weight: bold; color: #0f2747;
        border-top: 1px solid #cbd5e1;
    }

    /* ---------------- Signatures ---------------- */
    .sign td {
        border: 1px solid #cbd5e1; padding: 6px 8px 4px; width: 25%;
        font-size: 8.5px;
    }
    .sign .role { font-size: 7.5px; text-transform: uppercase; letter-spacing: 0.6px; color: #64748b; }
    .sign .space { height: 34px; }
    .sign .who { border-top: 1px solid #94a3b8; padding-top: 3px; font-weight: bold; color: #111827; }
    .sign .when { color: #64748b; font-size: 7.5px; }

    /* ---------------- Footer / document control ---------------- */
    .doc-footer {
        border-top: 1px solid #dbe3ec; margin-top: 12px; padding-top: 5px;
        font-size: 7.5px; color: #94a3b8;
    }

    /* ---------------- Utility ---------------- */
    .muted { color: #64748b; }
    .strong { font-weight: bold; color: #111827; }
    .right { text-align: right; }
    .center { text-align: center; }
    .avoid-break { page-break-inside: avoid; }
    .note {
        border: 1px solid #e2e8f0; background: #f8fafc;
        padding: 6px 8px; font-size: 8.5px; color: #475569;
    }
</style>
