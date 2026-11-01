"""THE MIDNIGHT LIGHT-CLASS MAP (ADR 082) -- regenerate after adding a light
colour class to a page.

    python3 resources/css/tools/midnight-map.py

Scans resources/js for the colour utilities pages actually use (bg-white/80,
text-graphite-700, border-steel-100, hover:bg-graphite-50 ...), gives each
its Midnight Frosted Glass value, and rewrites the block between the
`midnight-map:start` and `midnight-map:end` markers in resources/css/app.css.
Everything is under `.dark`, so Light Mode is never touched, and it lives in
`@layer components`, so an explicit `dark:` utility on a page still wins.

Rules of the map (the reasoning is in ADR 082):
  * neutral ink 300-950 becomes the palette's ink scale (soft white #E8EEF6
    down to cool grey); light ink meant for a navy panel is left alone
  * semantic ink 500+ keeps its HUE and is lightened for a dark ground --
    red stays red, amber stays amber (ADR 081's meanings do not move)
  * white and pale neutral fills become dark translucent glass; pale
    semantic fills become a 12-26% wash of the same hue
  * pale borders, rings and dividers become soft steel #34465A
  * anything already dark (navy panels, strong 500+ fills) is unchanged
"""
import pathlib, re, sys
from collections import defaultdict

ROOT = pathlib.Path(__file__).resolve().parents[3]

NEUTRAL = {'graphite', 'slate', 'gray', 'navy', 'steel'}
SEM = {
    'red': (242, 107, 125), 'rose': (242, 107, 125),
    'amber': (242, 184, 75), 'yellow': (242, 184, 75), 'orange': (245, 160, 90),
    'emerald': (79, 209, 165), 'green': (79, 209, 165), 'lime': (79, 209, 165),
    'brand': (87, 149, 232), 'blue': (87, 149, 232), 'indigo': (120, 140, 240), 'sky': (90, 180, 240),
    'violet': (167, 139, 250), 'purple': (167, 139, 250),
    'teal': (45, 190, 210), 'cyan': (45, 190, 210),
}
SEM_TEXT = {  # (500-600, 700+)
    'red': ('#F28B97', '#F5A5AF'), 'rose': ('#F28B97', '#F5A5AF'),
    'amber': ('#F2B84B', '#F5C870'), 'yellow': ('#F2B84B', '#F5C870'), 'orange': ('#F5A962', '#F7BC85'),
    'emerald': ('#4FD1A5', '#7ADDB9'), 'green': ('#4FD1A5', '#7ADDB9'), 'lime': ('#4FD1A5', '#7ADDB9'),
    'brand': ('#7AAEF0', '#9CC2F4'), 'blue': ('#7AAEF0', '#9CC2F4'), 'sky': ('#7CC4F2', '#9FD3F5'),
    'indigo': ('#A3B0F8', '#B8C2FA'),
    'violet': ('#B9A6F6', '#C9BAF8'), 'purple': ('#B9A6F6', '#C9BAF8'),
    'teal': ('#5CC9DD', '#86DAE8'), 'cyan': ('#5CC9DD', '#86DAE8'),
}
INK = {950: '#E8EEF6', 900: '#E8EEF6', 800: '#E8EEF6', 700: '#D3DCE8', 600: '#BCC8D7', 500: '#A5B3C5', 400: '#8E9CB0', 300: '#6F7E92'}
SURF = (25, 38, 54)      # #192636
SURF2 = (34, 50, 70)     # #223246
EDGE = (52, 70, 90)      # #34465A

PREFIX = {'': '{s}', 'hover': '{s}:hover', 'focus': '{s}:focus', 'focus-visible': '{s}:focus-visible',
          'active': '{s}:active', 'group-hover': '.group:hover {s}'}

def rgba(c, a):
    a = max(0.0, min(1.0, a))
    return f'rgba({c[0]}, {c[1]}, {c[2]}, {a:.2f})'.replace('.00)', ')') if a < 1 else f'rgb({c[0]}, {c[1]}, {c[2]})'

def esc(cls):
    return re.sub(r'([:/.\[\]=])', r'\\\1', cls)

def value(util, color, shade, alpha):
    """Returns (property-kind, css value) or None to leave the class alone."""
    a = 1.0 if alpha is None else alpha
    if util == 'placeholder':
        return '#75849A'   # one dim example ink, about 4:1 on a field well
    if util == 'text':
        if color == 'white' or shade is None:
            return None
        if color in NEUTRAL:
            if color in ('steel', 'navy') and shade < 400:
                return None   # light ink meant for a navy panel
            if shade < 300:
                return None
            return INK.get(shade, INK[900])
        if color in SEM_TEXT and shade >= 500:
            return SEM_TEXT[color][0 if shade <= 600 else 1]
        return None
    if util == 'bg':
        if color == 'white':
            if alpha is not None and alpha < 0.4:
                return None   # a light wash over a navy panel
            return rgba(SURF, {1.0: .62, .95: .92, .9: .72, .85: .68, .8: .62, .7: .55, .6: .48, .5: .40, .45: .36}.get(a, .6 * a))
        if color in NEUTRAL:
            if color in ('navy',) or shade is None or shade >= 500:
                return None
            base = {25: (SURF2, .32), 50: (SURF2, .42), 100: (SURF2, .62), 200: ((46, 65, 87), 1), 300: ((61, 81, 104), 1), 400: ((86, 103, 125), 1)}[shade]
            return rgba(base[0], base[1] * a)
        if color in SEM and shade is not None and shade <= 200:
            return rgba(SEM[color], {25: .08, 50: .12, 100: .18, 200: .26}.get(shade, .12) * (a if alpha else 1))
        return None
    if util in ('border', 'ring', 'divide'):
        if color == 'white':
            if alpha is not None and alpha < 0.5:
                return None
            return 'rgba(150, 180, 220, 0.14)'
        if color in NEUTRAL:
            if color == 'navy' or shade is None or shade > 300:
                return None
            return rgba(EDGE, {25: .4, 50: .45, 100: .55, 200: .75, 300: .95}.get(shade, .6) * a)
        if color in SEM and shade is not None and shade <= 300:
            return rgba(SEM[color], {50: .2, 100: .26, 200: .34, 300: .42}.get(shade, .3) * (a if alpha else 1))
        return None
    if util in ('from', 'via', 'to'):
        if color == 'white':
            return rgba(SURF, .55 * a)
        if color in NEUTRAL and color != 'navy' and shade is not None and shade <= 100:
            return rgba(SURF2, .45 * a)
        if color in SEM and shade is not None and shade <= 100:
            return rgba(SEM[color], .12 * a)
        return None

DECL = {
    'text': 'color: {v};',
    'placeholder': 'color: {v};',
    'bg': 'background-color: {v};',
    'border': 'border-color: {v};',
    'ring': '--tw-ring-color: {v};',
    'divide': 'border-color: {v};',
    'from': '--tw-gradient-from: {v} var(--tw-gradient-from-position); --tw-gradient-to: rgba(25, 38, 54, 0) var(--tw-gradient-to-position); --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to);',
    'via': '--tw-gradient-to: rgba(25, 38, 54, 0) var(--tw-gradient-to-position); --tw-gradient-stops: var(--tw-gradient-from), {v} var(--tw-gradient-via-position), var(--tw-gradient-to);',
    'to': '--tw-gradient-to: {v} var(--tw-gradient-to-position);',
}

rx = re.compile(r'^(?:(hover|focus|focus-visible|active|group-hover):)?(bg|text|border|ring|divide|from|via|to|placeholder:text)-([a-z]+)(?:-(\d{2,3}|light))?(?:/([0-9.]+))?$')
# The semantic tokens in tailwind.config.js (success / warning / danger, each
# with a `-light` wash) mean the same as their hue: DEFAULT is read as the
# 600 ink, `-light` as the 50 wash.
TOKENS = {'danger': 'red', 'success': 'emerald', 'warning': 'amber'}
groups = defaultdict(list)
CLASS_RX = re.compile(r"(?:^|[\"' `])((?:(?:hover|focus|focus-visible|group-hover|active):)?(?:bg|text|border|ring|divide|from|to|via|placeholder:text)-[a-z]+(?:-(?:\d{2,3}|light))?(?:/[0-9.]+)?)(?=[\"' `]|$)", re.M)
found = set()
for f in (ROOT / 'resources/js').rglob('*.js*'):
    found.update(CLASS_RX.findall(f.read_text(encoding='utf-8')))
for cls in sorted(found):
    m = rx.match(cls)
    if not m:
        continue
    pre, util, color, shade, alpha = m.groups()
    pre = pre or ''
    if color in TOKENS:
        if util in ('bg', 'from', 'via', 'to') and shade != 'light':
            continue   # a solid semantic fill reads on dark as it is
        color, shade = TOKENS[color], ('50' if shade == 'light' else '600')
    elif shade == 'light':
        continue
    if util == 'placeholder:text':
        util = 'placeholder'
    shade = int(shade) if shade else None
    alpha = (float(alpha) / 100 if float(alpha) > 1 else float(alpha)) if alpha else None
    v = value(util, color, shade, alpha)
    if v is None:
        continue
    sel = '.' + esc(cls)
    if util == 'placeholder':
        sel = f'{sel}::placeholder'
    elif util == 'divide':
        sel = f'{sel} > :not([hidden]) ~ :not([hidden])'
    sel = PREFIX[pre].format(s=sel)
    groups[(util, pre != '', DECL[util].format(v=v))].append(sel)

out = []
order = ['bg', 'from', 'via', 'to', 'border', 'divide', 'ring', 'text', 'placeholder']
for util in order:
    for (u, hov, decl), sels in sorted(groups.items(), key=lambda kv: (kv[0][1], kv[0][2])):
        if u != util:
            continue
        out.append(',\n'.join(f'    .dark {s}' for s in sorted(sels)) + ' {\n        ' + decl + '\n    }')

css = ROOT / 'resources/css/app.css'
text = css.read_text(encoding='utf-8')
start, end = '    /* midnight-map:start */\n', '    /* midnight-map:end */\n'
i, j = text.index(start) + len(start), text.index(end)
css.write_text(text[:i] + '\n'.join(out) + '\n' + text[j:], encoding='utf-8')
print(f'midnight-map: {len(found)} colour classes scanned, {len(out)} rule groups written')
