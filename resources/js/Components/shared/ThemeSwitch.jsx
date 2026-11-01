import { useRef } from 'react';
import { usePage } from '@inertiajs/react';
import { Moon, Sun } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useTheme } from '@/lib/useTheme';

const OPTIONS = [
    { value: 'light', label: 'Light theme', Icon: Sun },
    { value: 'dark', label: 'Dark theme', Icon: Moon },
];

/**
 * v2.139.0 -- THE LIGHT / DARK SWITCH (ADR 082).
 *
 * A two-segment chrome pill in the top bar, before notifications and the
 * profile. A radio group, because exactly one theme is in force and the
 * two options are peers: Tab reaches the selected one, the arrow keys move
 * and choose (WAI-ARIA radio pattern), and each segment names itself.
 *
 * Renders nothing unless the server offers the dark theme to this person
 * (the shared `darkTheme` prop, Starter-only while the pilot is reviewed).
 */
export default function ThemeSwitch({ className }) {
    const { darkTheme } = usePage().props;
    const { theme, setTheme } = useTheme();
    const refs = useRef([]);

    if (!darkTheme) return null;

    const onKeyDown = (event, index) => {
        const step = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[event.key];
        if (!step) return;
        event.preventDefault();
        const next = (index + step + OPTIONS.length) % OPTIONS.length;
        setTheme(OPTIONS[next].value);
        refs.current[next]?.focus();
    };

    return (
        <div
            role="radiogroup"
            aria-label="Theme"
            className={cn('chrome flex h-9 shrink-0 items-center gap-0.5 rounded-full p-[3px]', className)}
        >
            {OPTIONS.map(({ value, label, Icon }, index) => {
                const selected = theme === value;
                return (
                    <button
                        key={value}
                        ref={(node) => { refs.current[index] = node; }}
                        type="button"
                        role="radio"
                        aria-checked={selected}
                        aria-label={label}
                        title={label}
                        tabIndex={selected ? 0 : -1}
                        onClick={() => setTheme(value)}
                        onKeyDown={(event) => onKeyDown(event, index)}
                        className={cn(
                            'theme-switch-option grid h-[28px] w-[30px] place-items-center rounded-full outline-none',
                            'transition-[color,background-color,box-shadow] duration-fast ease-out-soft',
                            'focus-visible:ring-4 focus-visible:ring-brand-500/25',
                            selected ? 'theme-switch-selected' : 'text-slate-500 hover:text-navy-900'
                        )}
                    >
                        <Icon className="h-[15px] w-[15px]" strokeWidth={2} aria-hidden="true" />
                    </button>
                );
            })}
        </div>
    );
}
