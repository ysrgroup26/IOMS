/**
 * Shared Sparkline (v2.44.0) -- a dependency-free inline trend line.
 *
 * WHY THIS EXISTS: "make the interface feel alive" is easy to fake and the
 * fake is always the same one -- a decorative squiggle that looks like data
 * and is not. This component is built so that cannot happen:
 *
 *   - it renders NOTHING unless it is handed at least two real points;
 *   - it never generates, smooths, interpolates or pads a series;
 *   - a flat series renders as a genuinely flat line, not a nudged one.
 *
 * So a surface only gains a trend where the caller already had real
 * history to pass. Anywhere IOMS has no series, the caller passes nothing
 * and the surface stays exactly as it was -- which is the honest outcome,
 * and the same principle as v2.39.0's "green is a claim".
 *
 * Plain SVG on purpose: Chart.js is already a dependency for the real
 * charts, but pulling a chart engine into a 60x20 inline glyph inside a
 * stat card would cost far more than it returns, and these render dozens
 * at a time in a list.
 *
 * Props:
 *   points     number[]  the real series, oldest -> newest
 *   tone       'brand' | 'success' | 'warning' | 'danger' | 'neutral'
 *   className  sizing hook; defaults to a card-sized glyph
 */
export default function Sparkline({ points, tone, className = 'h-7 w-full' }) {
    // Two points is the minimum that can express a direction. One value is
    // a number, not a trend, and drawing it would imply history that does
    // not exist.
    const series = Array.isArray(points) ? points.filter((p) => typeof p === 'number' && Number.isFinite(p)) : [];
    if (series.length < 2) return null;

    const max = Math.max(...series);
    const min = Math.min(...series);
    const range = max - min;

    const W = 100;
    const H = 28;
    const pad = 3;

    // A flat series sits on the centre line rather than being stretched to
    // fill the box -- stretching noise into a dramatic shape is exactly the
    // dishonesty this component exists to avoid.
    const y = (v) => (range === 0 ? H / 2 : pad + (H - pad * 2) * (1 - (v - min) / range));
    const x = (i) => (series.length === 1 ? W / 2 : (W / (series.length - 1)) * i);

    const line = series.map((v, i) => `${i === 0 ? 'M' : 'L'}${x(i).toFixed(2)},${y(v).toFixed(2)}`).join(' ');
    const area = `${line} L${W},${H} L0,${H} Z`;

    const tones = {
        brand: { stroke: 'text-brand-500', fill: 'text-brand-500' },
        success: { stroke: 'text-success', fill: 'text-success' },
        warning: { stroke: 'text-warning', fill: 'text-warning' },
        danger: { stroke: 'text-danger', fill: 'text-danger' },
        neutral: { stroke: 'text-graphite-400', fill: 'text-graphite-400' },
    };
    // No tone = inherit `currentColor` from the caller, which is how a KPI
    // category's own admin-configured colour reaches the line.
    const t = tone ? (tones[tone] || tones.brand) : { stroke: '' };

    // Unique gradient id per instance: several sparklines coexist on one
    // page and SVG ids are document-global.
    const gid = `spark-${tone || 'inherit'}-${series.length}-${Math.round(series[0] * 1000)}-${Math.round(max * 1000)}-${Math.round(min * 1000)}`;

    return (
        <svg
            viewBox={`0 0 ${W} ${H}`}
            preserveAspectRatio="none"
            className={`${t.stroke} ${className}`}
            aria-hidden="true"
            focusable="false"
        >
            <defs>
                <linearGradient id={gid} x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor="currentColor" stopOpacity="0.22" />
                    <stop offset="100%" stopColor="currentColor" stopOpacity="0" />
                </linearGradient>
            </defs>
            <path d={area} fill={`url(#${gid})`} stroke="none" />
            <path
                d={line}
                fill="none"
                stroke="currentColor"
                strokeWidth="1.75"
                strokeLinecap="round"
                strokeLinejoin="round"
                vectorEffect="non-scaling-stroke"
            />
        </svg>
    );
}
