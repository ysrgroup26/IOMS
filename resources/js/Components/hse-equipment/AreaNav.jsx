import { Link } from '@inertiajs/react';
import { HardHat, Package, ListChecks, ClipboardList, ShieldAlert } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * v2.133.0 (ADR 074) -- ONE STRIP FOR THE WHOLE AREA, GROUPED BY WHAT KIND
 * OF THING EACH LIST HOLDS.
 *
 *   Equipment       durable units the company owns, each with an ID, a
 *                   location and an inspection history.
 *   Supplies        consumables and first-aid stock. They are counted and
 *                   restocked, not inspected unit by unit, and are NOT
 *                   equipment.
 *   Reference Data  definitions the registers choose from: types, the
 *                   checklist templates inspections start from, and hazard
 *                   categories. Changing them changes the options, never the
 *                   records already filed.
 *
 * The register is its own route; the other four share Hse/Master and
 * switch client side there, which is why `onSelect` exists: on Master the
 * strip switches tabs in place, everywhere else it links.
 */
export const AREA_GROUPS = [
    { label: 'Equipment', kind: 'operational', tabs: [{ key: 'register', label: 'Equipment Register', icon: HardHat, route: 'safety-equipment.index' }] },
    { label: 'Supplies', kind: 'operational', tabs: [{ key: 'supplies', label: 'Supplies & First Aid', icon: Package }] },
    {
        label: 'Reference Data',
        kind: 'master',
        tabs: [
            { key: 'equipment-master', label: 'Equipment Types', icon: ListChecks },
            { key: 'templates', label: 'Inspection Templates', icon: ClipboardList },
            { key: 'hazards', label: 'Hazard Categories', icon: ShieldAlert },
        ],
    },
];

export default function AreaNav({ active, onSelect }) {
    return (
        <nav aria-label="Safety Equipment & Compliance" className="mb-4 -mx-1 flex items-stretch gap-5 overflow-x-auto border-b border-steel-200 px-1 dark:border-slate-800">
            {AREA_GROUPS.map((group) => (
                <div key={group.label} className="shrink-0">
                    <p className="px-1 pt-0.5 text-[10px] font-bold uppercase tracking-wider text-steel-600/80 dark:text-slate-500">{group.label}</p>
                    <div className="flex gap-1">
                        {group.tabs.map((tab) => {
                            const isActive = active === tab.key;
                            const className = cn(
                                'flex shrink-0 items-center gap-1.5 whitespace-nowrap border-b-2 px-3 py-1.5 text-xs font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500',
                                isActive
                                    ? 'border-brand-600 text-navy-900 dark:border-brand-400 dark:text-slate-50'
                                    : 'border-transparent text-graphite-500 hover:border-steel-300 hover:text-navy-800 dark:text-slate-400 dark:hover:text-slate-200'
                            );
                            const body = <><tab.icon className="h-3.5 w-3.5" aria-hidden="true" /> {tab.label}</>;

                            if (!tab.route && onSelect) {
                                return (
                                    <button key={tab.key} type="button" onClick={() => onSelect(tab.key)} aria-current={isActive ? 'page' : undefined} className={className}>
                                        {body}
                                    </button>
                                );
                            }

                            return (
                                <Link
                                    key={tab.key}
                                    href={tab.route ? route(tab.route) : route('hse.master', { tab: tab.key })}
                                    aria-current={isActive ? 'page' : undefined}
                                    className={className}
                                >
                                    {body}
                                </Link>
                            );
                        })}
                    </div>
                </div>
            ))}
        </nav>
    );
}
