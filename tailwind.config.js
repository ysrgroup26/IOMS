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
                brand: {
                    50: '#eff6ff',
                    100: '#dbeafe',
                    200: '#bfdbfe',
                    300: '#93c5fd',
                    400: '#60a5fa',
                    500: '#3b82f6',
                    600: '#2563eb',
                    700: '#1d4ed8',
                    800: '#1e40af',
                    900: '#1e3a8a',
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
                navy: { DEFAULT: '#0F2747', 700: '#17335A', 800: '#122A4A', 900: '#0F2747' },
                steel: { DEFAULT: '#3B82B6', 50: '#EAF3FB' },
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
                card: '0 1px 2px 0 rgba(15, 23, 42, 0.04)',
                'card-hover': '0 4px 12px 0 rgba(15, 23, 42, 0.08)',
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
