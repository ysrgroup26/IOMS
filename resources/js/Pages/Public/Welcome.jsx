import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import PublicLayout from '@/Layouts/PublicLayout';
import Reveal from '@/Components/public/Reveal';
import BlueprintBackdrop from '@/Components/public/BlueprintBackdrop';
import PlatformShowcase from '@/Components/public/PlatformShowcase';
import ConnectedOperations from '@/Components/public/ConnectedOperations';
import FragmentedToConnected from '@/Components/public/FragmentedToConnected';
import DepartmentGrid from '@/Components/public/DepartmentGrid';
import { Button } from '@/Components/ui/button';
import {
    ArrowRight, Users, FileCheck2, Flame, Eye, ClipboardCheck, ChevronDown,
    Ship, Building2, Factory, Wrench, Truck, Zap, Check, Plus, Smartphone, Building,
} from 'lucide-react';

/**
 * v2.18.0 (Public Website / Landing Page Foundation). The public IOMS
 * website -- the FIRST experience for someone discovering the product
 * from outside the app (social, referral, direct URL), reached at `/`
 * via `PublicController::home()` for any anonymous visitor. Deliberately
 * one long, anchor-navigated page (see `PublicLayout`'s own doc comment
 * for why) rather than a router-driven multi-page site.
 *
 * CONTENT HONESTY (this phase's own explicit, repeated rule): every
 * capability named below is a real, already-shipped module in this
 * codebase (cross-checked against `resources/js/lib/workspaces.js` and
 * `docs/MODULES.md`) -- nothing planned-but-unbuilt is presented as
 * available. No customer names, logos, counts, testimonials, or
 * certifications are used anywhere on this page; none exist yet, and
 * this pass was explicitly told not to invent them. Pricing is entirely
 * data-driven from `plans` (via `PricingService::publicPlans()` --
 * the exact same source the authenticated Plans page already uses), not
 * a single hardcoded amount.
 */
export default function PublicWelcome({ plans, steps = [], faqs = [], contactEmail }) {
    return (
        <PublicLayout>
            {/* v2.39.0: the page title was "IOMS — Industrial Operations
                Management Platform", which app.jsx then suffixed with
                " - IOMS", producing the duplicated browser tab title
                "IOMS — Industrial Operations Management Platform - IOMS".
                It also introduced a THIRD descriptor variant ("Operations
                Management Platform") alongside the canonical "Industrial
                Operations Platform". The title here is now just the
                descriptor -- app.jsx supplies the product name. */}
            <Head title="Industrial Operations Platform">
                <meta name="description" content="Connect field operations, HSE, workforce, and operational data in one platform." />
                <meta property="og:title" content="IOMS — Industrial Operations Platform" />
                <meta property="og:description" content="Connect field operations, HSE, workforce, and operational data in one platform." />
                <meta property="og:type" content="website" />
            </Head>

            <Hero />
            <TrustStatement />
            <ProblemSection />
            <PlatformOverview />
            <PtwHseStory />
            <FieldExperience />
            <ProductPreview />
            <Industries />
            <Pricing plans={plans} />
            <HowItWorks steps={steps} />
            <Faq faqs={faqs} />
            <FinalCta />
        </PublicLayout>
    );
}

/* ------------------------------------------------------------------ */
/* Section: Hero                                                       */
/* ------------------------------------------------------------------ */
const INDUSTRIES_STRIP = ['Shipyard', 'Construction', 'Manufacturing', 'Mining & Energy'];

/**
 * v2.45.0 -- the hero moves onto a deep navy atmospheric surface, the same
 * brand DNA as the sign-in gateway, so the first thing a visitor sees is an
 * industrial operations platform rather than a white marketing page with
 * blue blobs on it. The previous hero leaned on two animated glow blobs and
 * a grid over white -- decoration doing the work a real surface should.
 *
 * The orbit is KEPT and re-lit rather than replaced: eight real product
 * domains around a central hub is the single clearest statement that IOMS
 * is multi-domain and not an HSE tool, which is exactly the positioning
 * that needed strengthening. On navy the connecting lines finally read.
 *
 * Soft, not loud: one steel wash, one brand wash, one low-opacity technical
 * grid, and no pulsing animation. Depth comes from the surface itself.
 */
function Hero() {
    return (
        <section className="relative isolate overflow-hidden border-b border-navy-800 bg-navy-900 text-white">
            {/* v2.59.0: replaces a 56px grid plus two blurred blobs -- the
                same treatment every navy band on this page used, which is
                why three dark sections read as one flat blue field. See
                BlueprintBackdrop for what each layer is doing. */}
            <BlueprintBackdrop variant="hero" />

            <div className="relative mx-auto max-w-7xl px-4 py-20 sm:px-6 sm:py-28 lg:px-8">
                <div className="mx-auto max-w-3xl text-center">
                    <p className="text-xs font-semibold uppercase tracking-[0.2em] text-steel-300">
                        Industrial Operations Platform
                    </p>
                    <h1 className="mt-4 text-4xl font-semibold leading-[1.08] tracking-tight text-white sm:text-5xl lg:text-6xl">
                        One platform for how your<br className="hidden sm:block" /> whole operation actually runs.
                    </h1>

                    <div className="mx-auto mt-6 inline-flex flex-wrap items-center justify-center gap-x-2 gap-y-1 rounded-full border border-white/10 bg-white/[0.06] px-4 py-2 text-[11px] font-semibold uppercase tracking-wide text-steel-200 sm:text-xs">
                        <span className="text-white">Built for Industrial Operations</span>
                        <span className="hidden text-white/25 sm:inline">&middot;</span>
                        <span className="flex flex-wrap items-center justify-center gap-x-1.5">
                            {INDUSTRIES_STRIP.map((ind, i) => (
                                <span key={ind}>{ind}{i < INDUSTRIES_STRIP.length - 1 ? ' •' : ''}</span>
                            ))}
                        </span>
                    </div>

                    <p className="mx-auto mt-6 max-w-2xl text-base leading-relaxed text-navy-300 sm:text-lg">
                        Projects, people, work, materials and approvals all run in one connected platform — so what
                        happens on site reaches management as data, not as a stack of spreadsheets that no longer agree
                        with each other.
                    </p>

                    {/* One unmistakable primary, one quiet secondary. The
                        secondary is a surface-on-navy rather than a bordered
                        white button, so the pair reads as a hierarchy instead
                        of two buttons competing for the same weight. */}
                    <div className="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                        <Button size="lg" className="w-full sm:w-auto" asChild>
                            <Link href={route('get-started')}>Get Started <ArrowRight className="h-4 w-4" /></Link>
                        </Button>
                        <Button
                            size="lg"
                            variant="ghost"
                            className="w-full border border-white/15 bg-white/[0.06] text-white hover:bg-white/[0.12] hover:text-white sm:w-auto"
                            asChild
                        >
                            <Link href={route('sandbox')}>Try the Sandbox</Link>
                        </Button>
                    </div>
                </div>

                {/* v2.61.0: the platform visualisation moved into its own
                    component and learned to move -- see ConnectedOperations
                    for what the motion is saying and why it is not
                    decoration. The hero keeps owning the composition; it no
                    longer owns 40 lines of absolute positioning. */}
                <div className="mt-16 sm:mt-20">
                    <ConnectedOperations />
                </div>
            </div>
        </section>
    );
}


function TrustStatement() {
    return (
        <section className="border-b border-graphite-100 bg-white py-10">
            <div className="mx-auto max-w-4xl px-4 text-center sm:px-6 lg:px-8">
                <p className="text-lg font-medium text-graphite-700 sm:text-xl">
                    Departments stop working in separate systems. The operation starts working as one.
                </p>
            </div>
        </section>
    );
}

/* ------------------------------------------------------------------ */
/* Section: The Problem                                                */
/* ------------------------------------------------------------------ */
function ProblemSection() {
    return (
        <section className="border-b border-graphite-100 bg-gradient-to-b from-brand-50/50 to-brand-50/20 py-20">
            <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                <SectionHeading
                    eyebrow="The problem"
                    title="Your departments already have the data. They just don't share it."
                    subtitle="Procurement cannot see what the yard actually consumed. HSE cannot see which job the permit belongs to. Management rebuilds the same report every month."
                />

                <div className="mt-12">
                    <FragmentedToConnected />
                </div>
            </div>
        </section>
    );
}
/* ------------------------------------------------------------------ */
/* Section: Platform Overview                                          */
/* ------------------------------------------------------------------ */
function PlatformOverview() {
    return (
        <section id="platform" className="border-b border-graphite-100 bg-white py-20">
            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <SectionHeading
                    eyebrow="Platform"
                    title="One platform, every operational domain"
                    subtitle="Every workspace below runs in IOMS today — not a roadmap. They share one set of master data, one approval layer and one reporting layer."
                />

                {/* v2.61.0: the cards moved into DepartmentGrid, which also
                    corrects two names this section had wrong -- see that
                    component for what "Operations" and "Procurement &
                    Warehouse" were actually describing. */}
                <div className="mt-12">
                    <DepartmentGrid />
                </div>
            </div>
        </section>
    );
}
/* ------------------------------------------------------------------ */
/* Section: PTW -> HSE Story                                           */
/* ------------------------------------------------------------------ */
/**
 * v2.61.0 -- the chain, in English and with the department that owns each
 * step named.
 *
 * This section was a nine-item numbered list written in Indonesian on an
 * otherwise English page — the only place on the landing page where the
 * language changed mid-scroll. Worse for the argument it is making: a flat
 * list of nine steps says "there are nine steps", when the point is that
 * the record CROSSES DEPARTMENTS without anybody rekeying it. The owner of
 * each step is now the first thing on the row, and the row is tinted by
 * which side of the handover it sits on.
 */
const PTW_CHAIN = [
    { owner: 'Field', step: 'A field user raises a Permit To Work' },
    { owner: 'Field', step: 'The requester is recorded automatically from the signed-in account' },
    { owner: 'Field', step: 'Person in charge of the work, if the job has one' },
    { owner: 'People', step: 'Workforce picked from the employee directory' },
    { owner: 'Field', step: 'Submitted' },
    { owner: 'HSE', step: 'Reviewed by Health, Safety & Environment' },
    { owner: 'HSE', step: 'HIRADC, JSA or gas test attached where the work requires it' },
    { owner: 'HSE', step: 'Approved' },
    { owner: 'Field', step: 'The permit status is visible again on site' },
];

const OWNER_TONE = {
    Field: 'bg-steel-500/20 text-steel-100 ring-steel-400/30',
    HSE: 'bg-danger/20 text-red-200 ring-red-400/30',
    People: 'bg-brand-500/20 text-brand-200 ring-brand-400/30',
};

function PtwHseStory() {
    return (
        <section id="solutions" className="relative isolate overflow-hidden border-b border-navy-800 bg-navy-900 py-20 text-white">
            <BlueprintBackdrop />
            <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                <div className="text-center">
                    <p className="text-xs font-semibold uppercase tracking-[0.2em] text-brand-400">How the chain works</p>
                    <h2 className="mt-3 text-2xl font-semibold tracking-tight sm:text-3xl">One record, from the field to the approval that releases it</h2>
                    <p className="mx-auto mt-3 max-w-2xl text-sm text-graphite-300 sm:text-base">
                        Permit To Work is one worked example of how IOMS connects departments. The same shape applies to
                        a purchase requisition, a material issue or a work order: one record, the people accountable for
                        it, and the approvals that release it — all visible to management without anyone rekeying it.
                    </p>
                </div>

                <ol className="mx-auto mt-12 max-w-3xl">
                    {PTW_CHAIN.map((row, i) => (
                        <li key={row.step} className="relative flex items-center gap-3 pb-2 last:pb-0">
                            {/* The spine, drawn behind the numbers, so nine
                                rows read as one continuous record rather
                                than nine separate cards. */}
                            {i < PTW_CHAIN.length - 1 && (
                                <span aria-hidden="true" className="absolute left-3 top-6 h-full w-px bg-white/15" />
                            )}
                            <span className="relative z-10 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-500 text-xs font-semibold text-white ring-4 ring-navy-900">
                                {i + 1}
                            </span>
                            <span className="flex min-w-0 flex-1 flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border border-white/10 bg-white/5 px-4 py-3">
                                <span className={`shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 ring-inset ${OWNER_TONE[row.owner]}`}>
                                    {row.owner}
                                </span>
                                <span className="min-w-0 text-sm text-graphite-100 sm:text-[15px]">{row.step}</span>
                            </span>
                        </li>
                    ))}
                </ol>
            </div>
        </section>
    );
}
/* ------------------------------------------------------------------ */
/* Section: Field Experience                                           */
/* ------------------------------------------------------------------ */
function FieldExperience() {
    return (
        <section className="border-b border-graphite-100 bg-white py-20">
            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div className="grid grid-cols-1 items-center gap-10 lg:grid-cols-2">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-[0.2em] text-brand-600">My Work</p>
                        <h2 className="mt-3 text-2xl font-semibold tracking-tight text-graphite-900 sm:text-3xl">
                            Built for the people doing the work, not only those reporting on it.
                        </h2>
                        <p className="mt-4 text-base text-graphite-600">
                            Foremen, supervisors, technicians and operators land straight in My Work — a compact field
                            workspace built for a phone on site, not an office dashboard they have no use for.
                        </p>
                        <ul className="mt-6 space-y-2.5">
                            {['Raise a Permit To Work when the account is granted PTW Access', 'Your own permits and their live status', 'Work reference and work location shown separately', 'Tasks assigned to you'].map((f) => (
                                <li key={f} className="flex items-center gap-2 text-sm text-graphite-700">
                                    <Smartphone className="h-4 w-4 shrink-0 text-brand-500" /> {f}
                                </li>
                            ))}
                        </ul>
                    </div>
                    <Reveal className="rounded-xl border border-graphite-200 bg-graphite-50 p-4 shadow-card sm:p-6">
                        <MyWorkPreview />
                    </Reveal>
                </div>
            </div>
        </section>
    );
}

/**
 * v2.59.0 -- My Work as it actually looks: a phone-shaped frame, the real
 * greeting, action tiles first and live permits below them.
 *
 * The previous version was four bordered rectangles in a 2x2 grid, which
 * communicated neither the ordering the page's own copy describes
 * ("actions within thumb's reach, running permits second") nor that this
 * is a phone surface at all.
 */
function MyWorkPreview() {
    const actions = [
        { label: 'New Permit To Work', icon: Flame },
        { label: 'Safety Observation', icon: Eye },
        { label: 'My Tasks', icon: ClipboardCheck },
        { label: 'Daily Report', icon: FileCheck2 },
    ];

    return (
        <div className="mx-auto w-full max-w-[260px] overflow-hidden rounded-[20px] border-4 border-navy-900 bg-white shadow-panel">
            <div className="bg-navy-900 px-3.5 pb-3 pt-2.5">
                <p className="text-[9px] font-semibold uppercase tracking-[0.16em] text-steel-300">My Work</p>
                <p className="mt-0.5 text-[13px] font-semibold text-white">Good Morning, Team.</p>
            </div>

            <div className="space-y-2 p-3">
                {actions.map((a) => (
                    <div key={a.label} className="flex items-center gap-2.5 rounded-lg border border-graphite-100 bg-white p-2.5">
                        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-[9px] bg-gradient-to-br from-navy-800 to-brand-600 text-white">
                            <a.icon className="h-4 w-4" />
                        </span>
                        <span className="truncate text-[11px] font-semibold text-navy-900">{a.label}</span>
                    </div>
                ))}

                <p className="pt-1 text-[9px] font-semibold uppercase tracking-wide text-graphite-400">My permits</p>
                <div className="rounded-lg border border-graphite-100 p-2.5">
                    <div className="flex items-center justify-between">
                        <span className="text-[10px] font-semibold text-navy-900">PTW-2026-00184</span>
                        <span className="rounded-full bg-success/10 px-1.5 py-0.5 text-[9px] font-semibold text-success">Active</span>
                    </div>
                    <p className="mt-1 text-[9px] text-graphite-500">Hot work · Graving Dock 2</p>
                </div>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/* Section: Product Preview                                            */
/* ------------------------------------------------------------------ */
/**
 * v2.59.0 -- THE PAGE'S CENTREPIECE, and previously its weakest section.
 *
 * What stood here rendered two panels of placeholder furniture: four
 * figures that were literally the character "—" above a dashed empty
 * rectangle, and a permit with three grey bars where its content should
 * be. A visitor evaluating industrial software saw no product at all.
 *
 * Both panels were also HSE/PTW, so the entire product visualisation on a
 * page selling an Industrial Operations Platform showed one department.
 * PlatformShowcase replaces it with five real workspaces in one
 * application frame -- see that component for the reasoning.
 */
function ProductPreview() {
    return (
        <section className="border-b border-graphite-100 bg-white py-20">
            <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                <SectionHeading
                    eyebrow="Product"
                    title="This is the platform, not a screenshot of one module"
                    subtitle="Switch between the workspaces IOMS actually ships. Built from the real IOMS design system with illustrative operational data — not stock photography."
                />

                <Reveal className="mt-10">
                    <PlatformShowcase />
                </Reveal>
            </div>
        </section>
    );
}


/* ------------------------------------------------------------------ */
/* Section: Industries                                                 */
/* ------------------------------------------------------------------ */
function Industries() {
    const industries = [
        { label: 'Shipyard & Marine', icon: Ship }, { label: 'Construction', icon: Building },
        { label: 'Manufacturing', icon: Factory }, { label: 'Engineering & Fabrication', icon: Wrench },
        { label: 'Logistics', icon: Truck }, { label: 'Mining & Energy', icon: Zap },
        { label: 'Industrial Services', icon: Building2 },
    ];

    return (
        <section className="border-b border-graphite-100 bg-gradient-to-b from-brand-50/50 to-brand-50/20 py-16">
            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <p className="text-center text-xs font-semibold uppercase tracking-[0.2em] text-graphite-400">Built For</p>
                <div className="mt-8 flex flex-wrap items-center justify-center gap-3">
                    {industries.map((ind) => (
                        <div key={ind.label} className="flex items-center gap-2 rounded-full border border-graphite-200 bg-white px-4 py-2 text-sm text-graphite-600 shadow-card">
                            <ind.icon className="h-4 w-4 text-graphite-400" /> {ind.label}
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

/* ------------------------------------------------------------------ */
/* Section: Pricing (data-driven -- no hardcoded amount)                */
/* ------------------------------------------------------------------ */
/**
 * Purely PRESENTATIONAL framing -- not an entitlement, and not sourced
 * from the Package row. Keyed by `slug` with an empty-string fallback, so
 * a plan a Platform Admin adds later renders without copy rather than
 * blocking or inventing some.
 *
 * v2.28.0: Enterprise used to be framed as "...and custom tailoring", the
 * one place in the app where it read as bespoke work. IOMS is a
 * standardized product; Enterprise is the most COMPLETE tier of it.
 *
 * v2.61.0 -- ENGLISH, AND WHO THE TIER IS FOR.
 *
 * These four lines were the only Indonesian text left in this section of
 * an otherwise English page, so a visitor scrolling from the hero changed
 * language halfway down. They also described each tier in isolation; what
 * a buyer is actually deciding is which STEP UP they need, which is now
 * what the line says. The scope itself ("Everything in Professional,
 * plus...") comes from the server -- see PricingService::withLadderScope()
 * -- so this map never has to restate what a tier contains.
 */
const PLAN_FRAMING = {
    starter: 'For a team putting Health, Safety & Environment on one system for the first time.',
    professional: 'For an operation that also has to manage its people, competency and rosters.',
    business: 'For an operation running projects, materials and purchasing across several sites.',
    enterprise: 'For an organization that needs every department, unlimited capacity and cross-unit governance.',
};
function Pricing({ plans }) {
    const [interval, setInterval] = useState('monthly');
    const { version } = usePage().props;

    return (
        <section id="pricing" className="border-b border-graphite-100 bg-white py-20">
            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <SectionHeading eyebrow="Pricing" title="Plans that grow with your operation" subtitle="One standardized product at four levels of access and capacity. No hidden implementation fee, and no per-customer custom development." />

                {plans && plans.length > 0 ? (
                    <>
                        <div className="mt-8 flex justify-center">
                            <div className="inline-flex items-center rounded-lg border border-graphite-200 bg-white p-0.5 shadow-card">
                                {['monthly', 'yearly'].map((v) => (
                                    <button
                                        key={v}
                                        type="button"
                                        onClick={() => setInterval(v)}
                                        className={`rounded-md px-4 py-1.5 text-sm font-medium capitalize transition-colors ${interval === v ? 'bg-brand-600 text-white' : 'text-graphite-500 hover:text-graphite-800'}`}
                                    >
                                        {v}
                                    </button>
                                ))}
                            </div>
                        </div>

                        {/* v2.60.0: four tiers. Two-up from md, four-up only at
                            xl -- three across at lg would leave a lone card on a
                            second row, and squeezing four into lg makes each
                            narrower than its own price line. */}
                        <div className="mt-10 grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-4 xl:items-start">
                            {plans.map((plan) => {
                                const price = interval === 'monthly' ? plan.monthly : plan.yearly;
                                // Server-derived (config/plans.php -> PricingService),
                                // never inferred from the card's index -- which is what
                                // broke the moment a fourth tier arrived.
                                const emphasized = plan.is_popular;
                                const framing = PLAN_FRAMING[plan.slug] || '';
                                // v2.61.0: each tier stated against the one below it.
                                // Listing Business's five departments flat read as
                                // "five new things" when two of them are Starter's and
                                // Professional's -- see PricingService::withLadderScope().
                                const scope = plan.scope ?? { inherits_from: null, added: plan.department_workspaces ?? [], covers_everything: false };
                                return (
                                    <div
                                        key={plan.id}
                                        className={
                                            emphasized
                                                ? 'relative flex h-full flex-col rounded-2xl border-2 border-brand-500 bg-gradient-to-b from-brand-50/60 to-white p-6 shadow-card-hover xl:-translate-y-2'
                                                : 'flex h-full flex-col rounded-2xl border border-graphite-200 bg-white p-6 shadow-card'
                                        }
                                    >
                                        {/* v2.60.0: `is_popular` is a real field now
                                            (config/plans.php -> PricingService), so the
                                            emphasis can be stated rather than implied by
                                            a border the visitor has to interpret. */}
                                        {emphasized && (
                                            <span className="absolute -top-2.5 left-6 rounded-full bg-brand-600 px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white">
                                                Most popular
                                            </span>
                                        )}
                                        <h3 className="text-lg font-semibold text-graphite-900">{plan.name}</h3>
                                        {/* The tier's one-line answer to "who is this
                                            for", served from config/plans.php beside the
                                            scope it describes so the two cannot drift. */}
                                        {plan.positioning && (
                                            <p className="mt-1 text-xs font-semibold text-brand-700">{plan.positioning}</p>
                                        )}
                                        {framing && <p className="mt-1.5 text-sm leading-relaxed text-graphite-500">{framing}</p>}

                                        <div className="mt-5">
                                            <p className={emphasized ? 'text-3xl font-bold text-brand-700' : 'text-3xl font-bold text-graphite-900'}>{price.formatted}</p>
                                            {!plan.is_custom && price.amount !== null && (
                                                <p className="text-xs text-graphite-400">per {interval === 'monthly' ? 'month' : 'year'}</p>
                                            )}
                                            {/* v2.61.0: the annual saving stated where the
                                                cycle is chosen. Derived server-side from the
                                                plan's own two prices (PricingService::
                                                annualSaving) -- no percentage is written
                                                down on this page. */}
                                            {interval === 'yearly' && plan.annual_saving && (
                                                <p className="mt-1 text-xs font-medium text-success">
                                                    Save {plan.annual_saving.formatted} · {plan.annual_saving.percent}% less than monthly
                                                </p>
                                            )}
                                        </div>

                                        <ul className="mt-6 flex-1 space-y-2.5 border-t border-graphite-100 pt-5 text-sm text-graphite-600">
                                            <li className="flex items-center gap-2 font-medium text-graphite-800">
                                                <Users className="h-3.5 w-3.5 shrink-0 text-brand-500" /> {plan.max_users ? `${plan.max_users} user accounts` : 'Highest user capacity'}
                                            </li>
                                            {/* v2.53.0: capacity is ONE number. PTW Access is a
                                                permission granted inside IOMS, not a sold seat. */}
                                            <li className="flex items-center gap-2">
                                                <Building2 className="h-3.5 w-3.5 shrink-0 text-brand-500" /> {plan.max_companies ? `${plan.max_companies} Operating Unit${plan.max_companies > 1 ? 's' : ''}` : 'Multiple Operating Units'}
                                            </li>
                                            {/* v2.60.0: DEPARTMENTS only. The full grant also
                                                carries Reports and Settings, which every plan
                                                has -- listing them here made four tiers look
                                                more alike than they are. */}
                                            {scope.inherits_from && (
                                                <li className="flex items-center gap-2 pt-1 font-semibold text-navy-900">
                                                    <Plus className="h-3.5 w-3.5 shrink-0 text-brand-600" /> Everything in {scope.inherits_from}, plus
                                                </li>
                                            )}
                                            {scope.added.map((w) => (
                                                <li key={w} className="flex items-center gap-2"><Check className="h-3.5 w-3.5 shrink-0 text-brand-500" /> {w}</li>
                                            ))}
                                            {scope.covers_everything && (
                                                <li className="flex items-center gap-2 font-medium text-graphite-700">
                                                    <Check className="h-3.5 w-3.5 shrink-0 text-brand-500" /> Every department IOMS ships
                                                </li>
                                            )}
                                        </ul>

                                        {/* v2.52.0: this was a mailto: "Talk to Us" whenever a
                                            support address existed -- the same acquisition dead
                                            end v2.51.0 removed everywhere else and missed here.
                                            The plan CTA now goes to the real onboarding flow,
                                            carrying the chosen plan and cycle. */}
                                        <Button className="mt-7 w-full" variant={emphasized ? 'default' : 'outline'} asChild>
                                            <Link href={`${route('get-started')}?plan=${plan.slug}&cycle=yearly`}>Choose this plan</Link>
                                        </Button>
                                    </div>
                                );
                            })}
                        </div>
                    </>
                ) : (
                    <div className="mt-10 rounded-xl border border-dashed border-graphite-300 p-10 text-center">
                        <p className="text-base font-medium text-graphite-700">Plans are being finalized.</p>
                        <p className="mt-1 text-sm text-graphite-500">Contact us to discuss what your operation needs.</p>
                    </div>
                )}
            </div>
        </section>
    );
}

/* ------------------------------------------------------------------ */
/* Section: How It Works                                               */
/* ------------------------------------------------------------------ */
function HowItWorks({ steps: serverSteps = [] }) {
    // v2.52.0: the server's own list (PublicController::HOW_IT_WORKS), so
    // the landing page and /how-it-works can never describe two different
    // products -- they had already drifted while each kept its own copy.
    const steps = serverSteps.map((s) => ({ n: s.step, title: s.title, body: s.body }));

    return (
        <section id="how-it-works" className="border-b border-graphite-100 bg-gradient-to-b from-brand-50/50 to-brand-50/20 py-20">
            <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                <SectionHeading eyebrow="How It Works" title="From master data to management reporting" />
                <div className="mt-12 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    {steps.map((s) => (
                        <div key={s.n} className="rounded-xl border border-graphite-200 bg-white p-5 shadow-card">
                            <p className="text-2xl font-semibold text-graphite-200">{s.n}</p>
                            <h3 className="mt-2 text-sm font-semibold text-graphite-900">{s.title}</h3>
                            <p className="mt-1.5 text-sm text-graphite-500">{s.body}</p>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

/* ------------------------------------------------------------------ */
/* Section: FAQ                                                        */
/* ------------------------------------------------------------------ */
// v2.52.0: the FAQ now comes from PublicController, the same source
// /faq renders, so the two can never drift apart. The landing shows the
// first few; the full list is one click away.


function Faq({ faqs = [] }) {
    return (
        <section id="faq" className="border-b border-graphite-100 bg-white py-20">
            <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                <SectionHeading eyebrow="FAQ" title="Frequently asked questions" />
                <div className="mt-10 divide-y divide-graphite-100 rounded-xl border border-graphite-200">
                    {faqs.map((item) => (
                        <details key={item.q} className="group p-5">
                            <summary className="flex cursor-pointer list-none items-center justify-between gap-3 text-sm font-medium text-graphite-800 [&::-webkit-details-marker]:hidden">
                                {item.q}
                                <ChevronDown className="h-4 w-4 shrink-0 text-graphite-400 transition-transform group-open:rotate-180" />
                            </summary>
                            <p className="mt-3 text-sm leading-relaxed text-graphite-600">{item.a}</p>
                        </details>
                    ))}
                </div>
            </div>
        </section>
    );
}

/* ------------------------------------------------------------------ */
/* Section: Final CTA                                                  */
/* ------------------------------------------------------------------ */
function FinalCta() {
    return (
        <section className="relative isolate overflow-hidden bg-navy-900 py-20 text-white">
            <BlueprintBackdrop />
            <div className="mx-auto max-w-3xl px-4 text-center sm:px-6 lg:px-8">
                <h2 className="text-2xl font-semibold tracking-tight sm:text-3xl">Ready to connect your operation?</h2>
                <p className="mx-auto mt-3 max-w-xl text-sm text-graphite-300 sm:text-base">
                    Try the Sandbox first, or choose a plan and register your company — your workspace is provisioned
                    once payment is confirmed.
                </p>
                <div className="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    <Button size="lg" className="w-full sm:w-auto" asChild><Link href={route('get-started')}>Get Started <ArrowRight className="h-4 w-4" /></Link></Button>
                    <Button size="lg" variant="outline" className="w-full border-white/20 bg-transparent text-white hover:bg-white/10 sm:w-auto" asChild>
                        <Link href={route('sandbox')}>Try the Sandbox</Link>
                    </Button>
                </div>
            </div>
        </section>
    );
}

/* ------------------------------------------------------------------ */
/* Shared: Section heading                                             */
/* ------------------------------------------------------------------ */
/**
 * v2.59.0: reveals on scroll. Putting it here rather than at each call
 * site means every section on the page inherits the same single gesture
 * for free, and there is exactly one place to change or remove it.
 */
function SectionHeading({ eyebrow, title, subtitle }) {
    return (
        <Reveal className="mx-auto max-w-2xl text-center">
            <p className="text-xs font-semibold uppercase tracking-[0.2em] text-brand-600">{eyebrow}</p>
            <h2 className="mt-3 text-2xl font-semibold tracking-tight text-graphite-900 sm:text-3xl">{title}</h2>
            {subtitle && <p className="mt-3 text-sm text-graphite-600 sm:text-base">{subtitle}</p>}
        </Reveal>
    );
}
