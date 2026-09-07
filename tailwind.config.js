/** @type {import('tailwindcss').Config} */
export default {
    darkMode: ['class'],
    content: [
        './resources/**/*.blade.php',
        './resources/**/*.jsx',
        './resources/**/*.js',
    ],
    theme: {
        container: {
            center: true,
            padding: '1.5rem',
        },
        extend: {
            // v2.54.0 -- THE CHECKBOX WAS INVISIBLE EVERYWHERE.
            //
            // Tailwind's default spacing scale carries half-steps only up to
            // 3.5, so `h-4.5 w-4.5` generated NO CSS AT ALL. Three components
            // used it, and one of them was `ui/checkbox.jsx` -- meaning every
            // checkbox in IOMS (49 of them, across module visibility, role
            // permissions, PPE selection and more) rendered at zero size. The
            // control was still there and still clickable; there was simply
            // nothing to see, and no way to tell checked from unchecked.
            //
            // Fixed by defining the step rather than by rewriting the classes:
            // 18px is a deliberate size between Tailwind's 16 and 20, the three
            // call sites all meant it, and this way a fourth cannot silently
            // fail the same way.
            spacing: { 4.5: '1.125rem' },
            colors: {
                border: 'hsl(var(--border))',
                input: 'hsl(var(--input))',
                ring: 'hsl(var(--ring))',
                background: 'hsl(var(--background))',
                foreground: 'hsl(var(--foreground))',
                primary: {
                    DEFAULT: 'hsl(var(--primary))',
                    foreground: 'hsl(var(--primary-foreground))',
                },
                secondary: {
                    DEFAULT: 'hsl(var(--secondary))',
                    foreground: 'hsl(var(--secondary-foreground))',
                },
                destructive: {
                    DEFAULT: 'hsl(var(--destructive))',
                    foreground: 'hsl(var(--destructive-foreground))',
                },
                muted: {
                    DEFAULT: 'hsl(var(--muted))',
                    foreground: 'hsl(var(--muted-foreground))',
                },
                accent: {
                    DEFAULT: 'hsl(var(--accent))',
                    foreground: 'hsl(var(--accent-foreground))',
                },
                card: {
                    DEFAULT: 'hsl(var(--card))',
                    foreground: 'hsl(var(--card-foreground))',
                },
                // IOMS brand palette
                // v2.44.0: `brand` was Tailwind's stock blue -- a bright,
                // slightly violet web blue (#2563eb) that never belonged to the
                // same family as navy (#0F2747) and steel (#3B82B6). Side by
                // side it read as a different product's accent bolted onto an
                // industrial shell. The mid/dark stops are re-cut along the
                // navy<->steel axis: still confident enough to carry a primary
                // CTA, no longer a generic SaaS blue. The 50-200 tints are left
                // alone -- they are used as near-white wash backgrounds where
                // the hue barely reads and changing them would shift dozens of
                // surfaces for no gain.
                brand: {
                    50: '#eff6ff',
                    100: '#dbeafe',
                    200: '#bfdbfe',
                    300: '#8FBEEC',
                    400: '#5CA0EA',
                    500: '#3B85DD',
                    600: '#2166C4',
                    700: '#1A5099',
                    800: '#163F78',
                    900: '#12305A',
                },
                graphite: {
                    50: '#f8fafc',
                    100: '#f1f5f9',
                    200: '#e2e8f0',
                    300: '#cbd5e1',
                    400: '#94a3b8',
                    500: '#64748b',
                    600: '#475569',
                    700: '#334155',
                    800: '#1e293b',
                    900: '#0f172a',
                },
                // v1.11.12 (Final Visual Design System pass): exact-hex
                // semantic tokens for the three shades that DIDN'T
                // already match this pass's spec. Tailwind's own
                // `emerald-600`/`amber-600`/`red-600` (what StatCard's
                // accent classes used before this pass) render
                // #059669/#d97706/#dc2626, not the #16A34A/#F59E0B/
                // #EF4444 this spec calls for (those are actually
                // Tailwind's `green-600`/`amber-500`/`red-500` -- easy to
                // reach for the wrong shade by name alone). Named `success`/
                // `warning`/`danger` here (deliberately NOT `green`/
                // `amber`/`red`/`purple` -- those are Tailwind's own
                // built-in palette names; `extend.colors` REPLACES a
                // built-in palette entirely rather than merging into it,
                // so reusing one of those names would have silently
                // deleted every other shade of that color Tailwind ships,
                // e.g. `red-50`/`red-100` used elsewhere in the app for
                // unrelated things). Purple needed no new token at all --
                // Tailwind's own `violet-600`/`violet-50` are already
                // exactly `#7C3AED`/`#F5F3FF`, confirmed before adding
                // anything here.
                // v1.11.13: success-light corrected #F0FDF4 -> #ECFDF5 --
                // the latest reference screenshots + spec restate this
                // exact hex (Tailwind's own emerald-50, not green-50).
                success: { DEFAULT: '#16A34A', light: '#ECFDF5' },
                warning: { DEFAULT: '#F59E0B', light: '#FFFBEB' },
                danger: { DEFAULT: '#EF4444', light: '#FEF2F2' },
                // v2.36.0 (Visual System 2.0). Two new tokens completing
                // the directional palette this pass's own directive
                // names explicitly -- `brand` (IOMS Blue, #2563EB is
                // already brand-600 exactly) and `graphite` (Slate,
                // #475569 is already graphite-600 exactly) already
                // matched, so only Deep Navy and Steel Blue were
                // genuinely missing from the token system. Added here
                // rather than hardcoded per-component so every "strong
                // primary summary surface" this pass builds (Dashboard
                // hero, sidebar) draws from the same shared tokens.
                // v2.42.0: the navy/steel ramps were introduced in v2.36.0 with
                // only the few stops that pass needed. Extended here (purely
                // ADDITIVE -- every pre-existing stop keeps its exact hex) so
                // navy can carry a real surface hierarchy (panel / raised /
                // border / muted text) instead of one flat block.
                navy: { DEFAULT: '#0F2747', 300: '#7C94B4', 400: '#54719A', 500: '#2F5484', 600: '#1F406B', 700: '#17335A', 800: '#122A4A', 900: '#0F2747', 950: '#0A1C33' },
                steel: { DEFAULT: '#3B82B6', 50: '#EAF3FB', 100: '#D3E6F5', 200: '#AECFEA', 300: '#82B4DC', 400: '#5C9BCC', 500: '#3B82B6', 600: '#2E6894', 700: '#245273' },
            },
            borderRadius: {
                lg: 'var(--radius)',
                md: 'calc(var(--radius) - 2px)',
                sm: 'calc(var(--radius) - 4px)',
                xl: 'calc(var(--radius) + 4px)',
            },
            fontFamily: {
                sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
            },
            boxShadow: {
                // v1.11.12: simplified to the exact single-layer shadow
                // this pass's spec calls for ("Do NOT use strong
                // shadows") -- was a two-layer shadow with a second,
                // more visible 0.06-alpha layer stacked on top.
                // v2.43.0: the workspace ground is no longer near-white, so a
                // 0.04-alpha shadow that used to be the only thing separating a
                // card from the page now has a real tonal step doing that job
                // too. Nudged just enough for cards to read as RAISED on the
                // tinted ground -- still a single soft layer, still not the
                // "strong shadows" the v1.11.12 spec ruled out.
                card: '0 1px 2px 0 rgba(15, 39, 71, 0.06)',
                'card-hover': '0 6px 16px -2px rgba(15, 39, 71, 0.10)',
                // Panel = a section container that owns a region of the page
                // (module panels, page header). One step above a data card.
                panel: '0 2px 6px -1px rgba(15, 39, 71, 0.07)',
                // v2.44.0: elevation for surfaces that should feel lit rather
                // than merely outlined -- KPI/stat surfaces and domain panels.
                // A cool navy-tinted shadow -- deliberately a NEUTRAL depth cue
                // that happens to sit in the palette, not a coloured glow. The
                // brand-coloured `glow` token that lived here was removed in
                // v2.46.0: nothing used it, and a glowing interface is the
                // opposite of the intended restraint.
                lift: '0 10px 24px -8px rgba(15, 39, 71, 0.18), 0 2px 6px -2px rgba(15, 39, 71, 0.08)',
            },
            keyframes: {
                'fade-in': {
                    '0%': { opacity: '0', transform: 'translateY(4px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
                // v2.27.0 (Public Website & Auth Visual Transformation).
                // Two new, deliberately subtle/slow keyframes for the
                // public site's ambient motion (Part 6 of that pass's own
                // directive: "subtle, slow, professional... not a gaming
                // website") -- plain CSS, no new dependency. Both are
                // wrapped in `motion-safe:` at every call site (Tailwind's
                // built-in `prefers-reduced-motion` variant), never
                // applied unconditionally.
                float: {
                    '0%, 100%': { transform: 'translateY(0px)' },
                    '50%': { transform: 'translateY(-10px)' },
                },
                'pulse-glow': {
                    '0%, 100%': { opacity: '0.5' },
                    '50%': { opacity: '0.9' },
                },
            },
            animation: {
                'fade-in': 'fade-in 0.2s ease-out',
                float: 'float 6s ease-in-out infinite',
                'float-slow': 'float 9s ease-in-out infinite',
                'pulse-glow': 'pulse-glow 4s ease-in-out infinite',
            },
        },
    },
    plugins: [require('tailwindcss-animate')],
};
