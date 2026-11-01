import { useCallback, useEffect, useState } from 'react';

/**
 * v2.139.0 -- MIDNIGHT FROSTED GLASS: THE ONE THEME SWITCH (ADR 082).
 *
 * This replaces the dormant v1 hook (a `DARK_MODE_ENABLED = false` flag
 * duplicated in app.blade.php) rather than sitting beside it, so IOMS still
 * has exactly one theme system: a `dark` class on <html> that every `.dark`
 * token in app.css and every `dark:` utility reads.
 *
 * WHO MAY SEE IT is the server's answer, not this file's: the shared
 * `darkTheme` prop (App\Support\DarkTheme -- Starter tenants while the
 * pilot is reviewed). The inline script in app.blade.php asks the same
 * class before first paint; this module re-applies on every Inertia
 * navigation, because signing in or out never reloads the document -- the
 * login page would otherwise stay dark after logout, and the dashboard
 * light after login, until somebody pressed F5.
 *
 * THE PREFERENCE: 'light' | 'dark' stored under `ioms-theme`, or nothing,
 * meaning "follow the system" (and follow it live while nothing is stored).
 * localStorage is per browser, so it survives refresh, logout and login on
 * the same device; it is deliberately not a column, because the pilot
 * changes no schema.
 */
const STORAGE_KEY = 'ioms-theme';
const listeners = new Set();
let available = false;

function stored() {
    try {
        const value = window.localStorage.getItem(STORAGE_KEY);
        return value === 'light' || value === 'dark' ? value : null;
    } catch {
        return null;
    }
}

function systemPrefersDark() {
    return typeof window !== 'undefined' && !!window.matchMedia?.('(prefers-color-scheme: dark)').matches;
}

export function resolvedTheme() {
    if (!available) return 'light';
    return stored() ?? (systemPrefersDark() ? 'dark' : 'light');
}

/**
 * Applies the theme with a short cross-fade. The fade is a class held for
 * one transition (~220ms), not a permanent `transition` on every element --
 * that would make every hover and every list repaint pay for it, forever.
 * Reduced motion collapses it through the global rule in app.css.
 */
function apply({ animate = false } = {}) {
    if (typeof document === 'undefined') return;
    const root = document.documentElement;
    const dark = resolvedTheme() === 'dark';
    if (root.classList.contains('dark') === dark) return notify();

    if (animate) {
        root.classList.add('theme-switching');
        window.clearTimeout(apply.timer);
        apply.timer = window.setTimeout(() => root.classList.remove('theme-switching'), 260);
    }
    root.classList.toggle('dark', dark);
    root.style.colorScheme = dark ? 'dark' : 'light';
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', dark ? '#101925' : '#ffffff');
    notify();
}

function notify() {
    listeners.forEach((fn) => fn());
}

/** Called from app.jsx with every page's props (the first and each visit). */
export function syncThemeAvailability(isAvailable) {
    available = !!isAvailable;
    apply();
}

export function setThemePreference(theme) {
    try {
        window.localStorage.setItem(STORAGE_KEY, theme);
    } catch {
        // Private mode or blocked storage: the switch still works for this
        // page, it just will not be remembered.
    }
    apply({ animate: true });
}

if (typeof window !== 'undefined') {
    window.matchMedia?.('(prefers-color-scheme: dark)').addEventListener?.('change', () => {
        if (!stored()) apply({ animate: true });
    });
    // Another tab changed the preference.
    window.addEventListener('storage', (event) => {
        if (event.key === STORAGE_KEY) apply();
    });
}

export function useTheme() {
    const [theme, setTheme] = useState(resolvedTheme);

    useEffect(() => {
        const update = () => setTheme(resolvedTheme());
        listeners.add(update);
        update();
        return () => listeners.delete(update);
    }, []);

    const choose = useCallback((next) => setThemePreference(next), []);

    return { theme, setTheme: choose, available };
}
