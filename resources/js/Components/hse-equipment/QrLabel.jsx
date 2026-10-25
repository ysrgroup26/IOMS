import { useEffect, useState } from 'react';
import QRCode from 'qrcode';
import { Printer } from 'lucide-react';
import { Button } from '@/Components/ui/button';

/**
 * v2.133.0 -- A LABEL THAT OPENS THE RECORD.
 *
 * The code carries this unit's own URL and nothing else: no status, no
 * date, no name. Scanning it opens the record through the ordinary sign-in
 * and tenant checks, so a label photographed on a wall reveals nothing the
 * scanner could not already see, and the standing it shows is always
 * today's rather than whatever was true when the label was printed.
 */
export default function QrLabel({ url, code, name, location }) {
    const [svg, setSvg] = useState(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        let cancelled = false;
        QRCode.toString(url, { type: 'svg', margin: 1, errorCorrectionLevel: 'M', color: { dark: '#0F2747', light: '#FFFFFF' } })
            .then((out) => { if (!cancelled) setSvg(out); })
            .catch(() => { if (!cancelled) setFailed(true); });
        return () => { cancelled = true; };
    }, [url]);

    function print() {
        const w = window.open('', '_blank', 'width=420,height=560');
        if (!w) return;
        const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
        w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${esc(code || name)}</title>
<style>
  @page { size: 62mm 80mm; margin: 4mm; }
  body { font-family: Inter, Arial, sans-serif; color: #0F2747; margin: 0; text-align: center; }
  .qr svg { width: 48mm; height: 48mm; }
  .code { font: 700 14pt 'IBM Plex Mono', monospace; margin-top: 2mm; letter-spacing: .02em; }
  .name { font-size: 9pt; font-weight: 700; margin-top: 1mm; }
  .loc { font-size: 8pt; color: #3B82B6; margin-top: .5mm; }
  .brand { font-size: 6.5pt; color: #54719A; margin-top: 2mm; text-transform: uppercase; letter-spacing: .12em; }
</style></head><body>
<div class="qr">${svg}</div>
<div class="code">${esc(code || '')}</div>
<div class="name">${esc(name)}</div>
${location ? `<div class="loc">${esc(location)}</div>` : ''}
<div class="brand">Scan to open the inspection record</div>
<script>window.onload = function () { window.print(); };</script>
</body></html>`);
        w.document.close();
    }

    if (failed) return <p className="text-xs text-graphite-400">QR label could not be generated in this browser.</p>;

    return (
        <div className="flex items-center gap-4">
            <div
                className="h-28 w-28 shrink-0 rounded-lg border border-steel-200 bg-white p-1.5 [&_svg]:h-full [&_svg]:w-full"
                role="img"
                aria-label={`QR code that opens the record for ${code || name}`}
                // The SVG is produced by the qrcode library from this
                // record's own URL; nothing user-typed reaches it.
                dangerouslySetInnerHTML={svg ? { __html: svg } : undefined}
            />
            <div className="min-w-0 space-y-2">
                <p className="text-xs leading-relaxed text-graphite-500 dark:text-slate-400">
                    Tempel label ini pada unit. Pindai untuk membuka catatan ini setelah masuk ke IOMS.
                </p>
                <Button variant="outline" size="sm" onClick={print} disabled={!svg}><Printer className="h-3.5 w-3.5" /> Print Label</Button>
            </div>
        </div>
    );
}
