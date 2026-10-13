import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { accentFor } from './accents';
import { Zap, ArrowRight } from 'lucide-react';

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
 *
 * v2.116.0 -- TEXT-FORWARD, PER THE APPROVED REFERENCE. These were icon
 * tiles with a label underneath: a filled container, a glyph, and the
 * words as a caption. The action is the words, so the words lead, the
 * icon shrinks to an outline marker and a directional arrow says the tile
 * goes somewhere. One row of six decorative containers was the single
 * heaviest piece of furniture on the page.
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

            <div className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                {actions.map((action) => {
                    const tone = accentFor(action.accent);
                    const Icon = action.icon;

                    return (
                        <Link
                            key={action.label}
                            href={action.href}
                            className={cn(
                                'group flex items-center gap-2.5 rounded-xl border border-graphite-200/70 bg-white px-3 py-2.5',
                                'transition-all duration-200 hover:border-graphite-300 hover:shadow-[0_6px_18px_rgba(16,24,40,0.07)]',
                                'dark:border-slate-800 dark:bg-slate-900 dark:hover:border-slate-700'
                            )}
                        >
                            <Icon className={cn('h-4 w-4 shrink-0', tone.value)} />

                            <span className="min-w-0 flex-1 truncate text-[12px] font-semibold leading-tight text-graphite-800 dark:text-slate-100">
                                {action.label}
                            </span>

                            <ArrowRight className="h-3.5 w-3.5 shrink-0 text-graphite-300 transition-transform duration-200 group-hover:translate-x-0.5 group-hover:text-graphite-500 dark:text-slate-700" />
                        </Link>
                    );
                })}
            </div>

        </section>
    );
}
