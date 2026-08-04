import { useState, useEffect, useCallback } from 'react';

const STORAGE_KEY = 'ioms-theme';

// Feature flag (Final Bug Fix Before Beta): Dark Mode is functionally
// complete but temporarily disabled app-wide per explicit instruction --
// NOT deleted. Flipping this back to `true` in a future version restores
// full access with zero other code changes: the toggle button becomes
// visible again (see AuthenticatedLayout's use of DARK_MODE_ENABLED) and
// this hook resumes reading localStorage/OS preference instead of always
// forcing 'light'.
export const DARK_MODE_ENABLED = false;

function getInitialTheme() {
    if (typeof window === 'undefined') return 'light';
    if (!DARK_MODE_ENABLED) return 'light';
    const stored = window.localStorage.getItem(STORAGE_KEY);
    if (stored === 'light' || stored === 'dark') return stored;
    // Respect the OS-level preference on first visit, before any explicit choice exists.
    return window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

/**
 * Theme system (Phase 1 foundation, item 7). The `.dark` CSS variable set
 * already existed in resources/css/app.css but nothing ever toggled it --
 * this hook is the missing piece. Persists the user's explicit choice in
 * localStorage (this is real application code running in the user's
 * browser, not an in-chat artifact, so localStorage is the correct,
 * standard tool for "remember user preference" here) and applies/removes
 * the `dark` class on <html>, which is what every `.dark { ... }`
 * variable override in app.css targets.
 */
export function useTheme() {
    const [theme, setTheme] = useState(getInitialTheme);

    useEffect(() => {
        const root = window.document.documentElement;
        root.classList.toggle('dark', theme === 'dark');
        window.localStorage.setItem(STORAGE_KEY, theme);
    }, [theme]);

    const toggleTheme = useCallback(() => {
        if (!DARK_MODE_ENABLED) return;
        setTheme((t) => (t === 'dark' ? 'light' : 'dark'));
    }, []);

    return { theme, setTheme, toggleTheme };
}
