import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { accentFor } from './accents';
import { Zap } from 'lucide-react';

/**
 * v2.109.0 -- THE SIX THINGS AN HSE USER STARTS.
 *
 * AUTHORIZATION IS NOT DECIDED HERE. Each entry is filtered by the page
 * against the same capability predicate its destination route already
 * enforces, so a hidden tile never means a blocked route and a visible
 * one never means a 403. Hiding rather than disabling, because a disabled
 * control that never becomes enabled is just a question with no answer.
 *
 * Varied accents, one meaning each: reporting an incident is red because
 * that is what it is, an observation amber, CAPA violet, PTW blue, PPE
 * emerald, reports blue. The point of the colour is recognition at a
 * glance, not decoration -- which is why the set is fixed rather than
 * cycled from a palette.
 */
export default function QuickAccess({ actions = [] }) {
    if (actions.length === 0) {
        return null;
    }

    return (
        <section className="rounded-2xl border border-graphite-200/80 bg-white p-4 shadow-[0_1px_2px_rgba(16,24,40,0.04)] dark:border-slate-800 dark:bg-slate-900">
            <header>
                <h2 className="flex items-center gap-1.5 text-[13px] font-bold text-graphite-900 dark:text-slate-50">
                    <Zap className="h-4 w-4 text-amber-500" /> Quick Access
                </h2>
                <p className="mt-0.5 text-[11px] text-graphite-400 dark:text-slate-500">
                    Tindakan yang paling sering dimulai dari halaman ini.
                </p>
            </header>

            <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                {actions.map((action) => {
                    const tone = accentFor(action.accent);
                    const Icon = action.icon;

                    return (
                        <Link
                            key={action.label}
                            href={action.href}
                            className={cn(
                                'group flex flex-col items-start gap-2 rounded-xl border border-graphite-200/70 bg-white p-3',
                                'transition-all duration-200 hover:-translate-y-0.5 hover:border-graphite-300 hover:shadow-[0_6px_18px_rgba(16,24,40,0.07)]',
                                'dark:border-slate-800 dark:bg-slate-900 dark:hover:border-slate-700'
                            )}
                        >
                            <span className={cn('inline-flex h-8 w-8 items-center justify-center rounded-lg ring-1', tone.icon)}>
                                <Icon className="h-4 w-4" />
                            </span>
                            <span className="text-[11.5px] font-semibold leading-tight text-graphite-700 dark:text-slate-200">
                                {action.label}
                            </span>
                        </Link>
                    );
                })}
            </div>
        </section>
    );
}
