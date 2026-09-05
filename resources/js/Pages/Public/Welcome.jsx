import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import PublicLayout from '@/Layouts/PublicLayout';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import {
    ArrowRight, HardHat, ShieldCheck, Users, BarChart3, FileCheck2, ClipboardList,
    Flame, Wind, Lock, AlertTriangle, Eye, ClipboardCheck, Clock, ChevronDown,
    Ship, Building2, Factory, Wrench, Truck, Zap, Cog, Check, Smartphone, Building,
    Warehouse, ShoppingCart, FolderKanban, LineChart, Sparkles,
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
export default function PublicWelcome({ plans }) {
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
            <HseWorkspace />
            <PeopleWorkforce />
            <OperationalData />
            <ProductPreview />
            <Industries />
            <Pricing plans={plans} />
            <HowItWorks />
            <Faq />
            <FinalCta />
        </PublicLayout>
    );
}

/* ------------------------------------------------------------------ */
/* Section: Hero                                                       */
/* ------------------------------------------------------------------ */
const INDUSTRIES_STRIP = ['Shipyards', 'Construction', 'Manufacturing', 'Heavy Industry'];

// v2.27.0 (Public Website & Auth Visual Transformation, Part 4/7). The
// platform-visualization node set -- 8 real domains around a central
// "IOMS" hub, each cross-checked against `PLATFORM_AREAS` /
// `resources/js/lib/workspaces.js` below (same source of truth every
// other section on this page already uses) so this visual never implies
// a capability that doesn't exist. Positions are plain percentage
// coordinates on a 100x100 circle (top, going clockwise) -- no JS
// trig/animation library, just static numbers computed once.
const ORBIT_NODES = [
    { label: 'HSE & Safety', icon: ShieldCheck, x: 50, y: 10 },
    { label: 'People', icon: Users, x: 84, y: 24 },
    { label: 'Operations', icon: Cog, x: 90, y: 50 },
    { label: 'Warehouse', icon: Warehouse, x: 84, y: 76 },
    { label: 'Procurement', icon: ShoppingCart, x: 50, y: 90 },
    { label: 'Logistics', icon: Truck, x: 16, y: 76 },
    { label: 'Projects', icon: FolderKanban, x: 10, y: 50 },
    { label: 'Reports', icon: LineChart, x: 16, y: 24 },
];

function Hero() {
    return (
        <section className="relative overflow-hidden border-b border-graphite-100 bg-gradient-to-b from-white via-brand-50/40 to-white">
            {/* v2.27.0: ambient background system -- "white -> very light
                blue -> soft blue" per this pass's own color direction,
                replacing the previous graphite-tinted blobs. Two slow
                `motion-safe:animate-pulse-glow` blobs (disabled entirely
                under prefers-reduced-motion, per Tailwind's built-in
                variant) plus a faint static grid for technical texture --
                all `pointer-events-none`/`aria-hidden`, purely decorative. */}
            <div className="pointer-events-none absolute -right-32 -top-32 h-[28rem] w-[28rem] rounded-full bg-brand-400 opacity-[0.10] blur-3xl motion-safe:animate-pulse-glow" aria-hidden="true" />
            <div className="pointer-events-none absolute -left-40 top-40 h-96 w-96 rounded-full bg-brand-300 opacity-[0.10] blur-3xl motion-safe:animate-pulse-glow" style={{ animationDelay: '2s' }} aria-hidden="true" />
            <div
                className="pointer-events-none absolute inset-0 opacity-[0.4]"
                aria-hidden="true"
                style={{
                    backgroundImage: 'linear-gradient(to right, rgba(37,99,235,0.05) 1px, transparent 1px), linear-gradient(to bottom, rgba(37,99,235,0.05) 1px, transparent 1px)',
                    backgroundSize: '48px 48px',
                    maskImage: 'linear-gradient(to bottom, black, transparent 85%)',
                }}
            />

            <div className="relative mx-auto max-w-7xl px-4 py-20 sm:px-6 sm:py-28 lg:px-8">
                <div className="mx-auto max-w-3xl text-center">
                    <p className="text-xs font-semibold uppercase tracking-[0.2em] text-brand-600">
                        Industrial Operations Platform
                    </p>
                    <h1 className="mt-4 text-4xl font-semibold leading-tight tracking-tight text-graphite-900 sm:text-5xl lg:text-6xl">
                        Run Your Industrial Operations<br className="hidden sm:block" /> in One Platform.
                    </h1>

                    {/* v2.27.0 (Part 5): the industry positioning statement
                        -- deliberately visually distinct (English, bold,
                        uppercase, its own bordered strip) from the
                        Indonesian explanatory sentence beneath it, per
                        this pass's own "should NOT be buried inside a
                        paragraph" instruction. */}
                    <div className="mx-auto mt-6 inline-flex flex-wrap items-center justify-center gap-x-2 gap-y-1 rounded-full border border-brand-200 bg-white/70 px-4 py-2 text-[11px] font-semibold uppercase tracking-wide text-brand-700 backdrop-blur-sm sm:text-xs">
                        <span className="text-graphite-900">Built for Industrial Operations</span>
                        <span className="hidden text-graphite-300 sm:inline">&middot;</span>
                        <span className="flex flex-wrap items-center justify-center gap-x-1.5">
                            {INDUSTRIES_STRIP.map((ind, i) => (
                                <span key={ind}>{ind}{i < INDUSTRIES_STRIP.length - 1 ? ' •' : ''}</span>
                            ))}
                        </span>
                    </div>

                    <p className="mx-auto mt-6 max-w-2xl text-base leading-relaxed text-graphite-600 sm:text-lg">
                        IOMS membantu perusahaan industri mengelola pekerjaan lapangan, keselamatan (HSE), tenaga
                        kerja, operasional, dan data perusahaan dalam satu platform -- dirancang untuk galangan
                        kapal, konstruksi, manufaktur, dan industri berat.
                    </p>
                    <div className="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                        <Button size="lg" className="w-full sm:w-auto" asChild><Link href={route('login')}>Get Started <ArrowRight className="h-4 w-4" /></Link></Button>
                        <Button size="lg" variant="outline" className="w-full sm:w-auto" asChild><a href="#platform">Explore IOMS</a></Button>
                    </div>
                </div>

                {/* v2.27.0 (Part 4/7): the platform visualization -- a
                    central IOMS hub with 8 real domains connected around
                    it, replacing the previous flat 5-tile "Field -> PTW ->
                    HSE -> Data -> Management" row. Desktop-only (`lg:`) --
                    absolute-positioned nodes on a percentage-based circle
                    don't reflow safely to a narrow viewport by shrinking
                    alone (per this pass's own "must not overflow on
                    mobile" rule), so mobile gets a separate, simple wrap-
                    grid fallback immediately below instead of a shrunk
                    copy of this same layout. */}
                <div className="relative mx-auto mt-20 hidden aspect-square max-w-xl lg:block">
                    <svg className="absolute inset-0 h-full w-full" viewBox="0 0 100 100" aria-hidden="true">
                        {ORBIT_NODES.map((n) => (
                            <line key={n.label} x1="50" y1="50" x2={n.x} y2={n.y} stroke="rgb(37 99 235 / 0.15)" strokeWidth="0.4" />
                        ))}
                    </svg>

                    {/* Central IOMS hub */}
                    <div className="absolute left-1/2 top-1/2 flex h-32 w-32 -translate-x-1/2 -translate-y-1/2 flex-col items-center justify-center rounded-full border border-brand-200 bg-white shadow-card-hover motion-safe:animate-pulse-glow">
                        <div className="flex h-14 w-14 items-center justify-center rounded-full bg-gradient-to-br from-brand-500 to-brand-700 text-white shadow-card">
                            <Sparkles className="h-6 w-6" />
                        </div>
                        <p className="mt-2 text-sm font-bold tracking-tight text-graphite-900">IOMS</p>
                    </div>

                    {ORBIT_NODES.map((n, i) => (
                        <div
                            key={n.label}
                            className="absolute flex -translate-x-1/2 -translate-y-1/2 flex-col items-center gap-1.5 rounded-xl border border-graphite-200 bg-white/90 px-3 py-2.5 shadow-card backdrop-blur-sm motion-safe:animate-float"
                            style={{ left: `${n.x}%`, top: `${n.y}%`, animationDelay: `${i * 0.4}s` }}
                        >
                            <n.icon className="h-4 w-4 text-brand-600" />
                            <span className="whitespace-nowrap text-[11px] font-medium text-graphite-700">{n.label}</span>
                        </div>
                    ))}
                </div>

                {/* Mobile/tablet fallback -- same 8 domains, plain wrap
                    grid, zero absolute positioning so there is nothing
                    that can overflow a narrow viewport. */}
                <div className="mx-auto mt-16 grid max-w-md grid-cols-2 gap-3 sm:grid-cols-4 lg:hidden">
                    {ORBIT_NODES.map((n) => (
                        <div key={n.label} className="flex flex-col items-center gap-1.5 rounded-xl border border-graphite-200 bg-white px-3 py-3 text-center shadow-card">
                            <n.icon className="h-4 w-4 text-brand-600" />
                            <span className="text-[11px] font-medium leading-tight text-graphite-700">{n.label}</span>
                        </div>
                    ))}
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
                    One connected system for industrial operational complexity.
                </p>
            </div>
        </section>
    );
}

/* ------------------------------------------------------------------ */
/* Section: The Problem                                                */
/* ------------------------------------------------------------------ */
function ProblemSection() {
    const fragments = ['Excel Sheets', 'WhatsApp Groups', 'Paper Forms', 'Separate HSE Records', 'Manual Approvals', 'Scattered Data'];

    return (
        <section className="border-b border-graphite-100 bg-gradient-to-b from-brand-50/50 to-brand-50/20 py-20">
            <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                <div className="text-center">
                    <h2 className="text-2xl font-semibold tracking-tight text-graphite-900 sm:text-3xl">
                        Industrial operations generate a lot of data.
                    </h2>
                    <p className="mt-3 text-base text-graphite-600">The problem is that it's usually fragmented.</p>
                </div>

                <div className="mt-10 grid grid-cols-2 gap-3 sm:grid-cols-3">
                    {fragments.map((f) => (
                        <div key={f} className="rounded-lg border border-graphite-200 bg-white px-3 py-4 text-center text-sm text-graphite-500 shadow-card">
                            {f}
                        </div>
                    ))}
                </div>

                <div className="mt-6 flex justify-center">
                    <ArrowRight className="h-6 w-6 rotate-90 text-graphite-300" />
                </div>

                <div className="mt-6 rounded-xl border-2 border-graphite-900 bg-white p-6 text-center shadow-card-hover sm:p-8">
                    <p className="text-xs font-semibold uppercase tracking-[0.2em] text-brand-600">IOMS</p>
                    <p className="mt-2 text-xl font-semibold text-graphite-900 sm:text-2xl">One Connected System</p>
                </div>
            </div>
        </section>
    );
}

/* ------------------------------------------------------------------ */
/* Section: Platform Overview                                          */
/* ------------------------------------------------------------------ */
const PLATFORM_AREAS = [
    { title: 'Field Operations', icon: HardHat, items: ['Permit To Work (PTW)', 'My PTW', 'Daily / Job Reports', 'Task Management'] },
    { title: 'HSE Management', icon: ShieldCheck, items: ['HSE Dashboard', 'Incident Management', 'Safety Observation', 'HSE Inspection', 'CAPA', 'JSA', 'HIRADC', 'Gas Test', 'LOTO'] },
    { title: 'People', icon: Users, items: ['Employee Management', 'Contractor Management', 'Visitor Management', 'PPE Management'] },
    { title: 'Operations', icon: Cog, items: ['Man-Hour Tracking', 'Waste Management', 'Work Center', 'Warehouse & Procurement'] },
    { title: 'Data & Insight', icon: BarChart3, items: ['Reports', 'Global Search', 'KPI Tracking', 'Operational Records'] },
];

function PlatformOverview() {
    return (
        <section id="platform" className="border-b border-graphite-100 bg-white py-20">
            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <SectionHeading eyebrow="Platform" title="Everything your operation runs on, in one place" />

                <div className="mt-12 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {PLATFORM_AREAS.map((area) => (
                        <div key={area.title} className="rounded-xl border border-graphite-200 p-6 shadow-card">
                            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-brand-50">
                                <area.icon className="h-5 w-5 text-brand-600" />
                            </div>
                            <h3 className="mt-4 text-base font-semibold text-graphite-900">{area.title}</h3>
                            <ul className="mt-3 space-y-1.5">
                                {area.items.map((i) => (
                                    <li key={i} className="flex items-start gap-1.5 text-sm text-graphite-600">
                                        <Check className="mt-0.5 h-3.5 w-3.5 shrink-0 text-brand-500" />
                                        <span>{i}</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

/* ------------------------------------------------------------------ */
/* Section: PTW -> HSE Story                                           */
/* ------------------------------------------------------------------ */
function PtwHseStory() {
    const flow = [
        'Field user creates PTW',
        'Requester automatically recorded',
        'Optional PIC / Supervisor',
        'Optional Workforce',
        'Submit',
        'HSE reviews',
        'HIRADC / JSA / Gas Test as applicable',
        'Approval',
        'Field sees PTW status',
    ];

    return (
        <section id="solutions" className="border-b border-graphite-100 bg-graphite-900 py-20 text-white">
            <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                <div className="text-center">
                    <p className="text-xs font-semibold uppercase tracking-[0.2em] text-brand-400">A Real Differentiator</p>
                    <h2 className="mt-3 text-2xl font-semibold tracking-tight sm:text-3xl">From Field Request to HSE Approval -- Connected</h2>
                    <p className="mx-auto mt-3 max-w-2xl text-sm text-graphite-300 sm:text-base">
                        A Permit To Work isn't a paper form in IOMS. It's one connected record from the moment a
                        field user requests it to the moment HSE closes it out.
                    </p>
                </div>

                <ol className="mx-auto mt-12 flex max-w-3xl flex-col gap-2">
                    {flow.map((step, i) => (
                        <li key={step} className="flex items-center gap-3 rounded-lg border border-white/10 bg-white/5 px-4 py-3">
                            <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-500 text-xs font-semibold text-white">
                                {i + 1}
                            </span>
                            <span className="text-sm text-graphite-100 sm:text-[15px]">{step}</span>
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
                        <p className="text-xs font-semibold uppercase tracking-[0.2em] text-brand-600">Field Experience</p>
                        <h2 className="mt-3 text-2xl font-semibold tracking-tight text-graphite-900 sm:text-3xl">
                            Built for people doing the work, not just managing it.
                        </h2>
                        <p className="mt-4 text-base text-graphite-600">
                            Field users get a dedicated, mobile-friendly experience -- fast, simple, and directly
                            connected to HSE. No enterprise clutter, no dense tables to scroll through.
                        </p>
                        <ul className="mt-6 space-y-2.5">
                            {['Create & submit PTW', 'My PTW -- track your own permits', 'Today\'s Jobs / Work Report', 'My Tasks'].map((f) => (
                                <li key={f} className="flex items-center gap-2 text-sm text-graphite-700">
                                    <Smartphone className="h-4 w-4 shrink-0 text-brand-500" /> {f}
                                </li>
                            ))}
                        </ul>
                    </div>
                    <div className="rounded-xl border border-graphite-200 bg-graphite-50 p-4 shadow-card sm:p-6">
                        <MockupFieldHome />
                    </div>
                </div>
            </div>
        </section>
    );
}

/* ------------------------------------------------------------------ */
/* Section: HSE Workspace                                              */
/* ------------------------------------------------------------------ */
function HseWorkspace() {
    const modules = [
        { label: 'PTW', icon: Flame }, { label: 'HIRADC', icon: FileCheck2 }, { label: 'JSA', icon: ClipboardList },
        { label: 'Gas Test', icon: Wind }, { label: 'Incident', icon: AlertTriangle }, { label: 'Safety Observation', icon: Eye },
        { label: 'Inspection', icon: ClipboardCheck }, { label: 'CAPA', icon: ShieldCheck }, { label: 'LOTO', icon: Lock },
    ];

    return (
        <section className="border-b border-graphite-100 bg-gradient-to-b from-brand-50/50 to-brand-50/20 py-20">
            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <SectionHeading eyebrow="HSE Workspace" title="From field activity to HSE oversight" subtitle="HSE gets the full control and review environment -- every PTW, hazard, and safety record in one workspace." />

                <div className="mt-12 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-9">
                    {modules.map((m) => (
                        <div key={m.label} className="flex flex-col items-center gap-2 rounded-lg border border-graphite-200 bg-white px-2 py-4 text-center shadow-card">
                            <m.icon className="h-5 w-5 text-brand-600" />
                            <span className="text-xs font-medium text-graphite-700">{m.label}</span>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

/* ------------------------------------------------------------------ */
/* Section: People / Workforce                                         */
/* ------------------------------------------------------------------ */
function PeopleWorkforce() {
    const chain = ['Employee', 'User Account', 'PTW Access', 'Requester', 'PIC', 'Workforce'];

    return (
        <section className="border-b border-graphite-100 bg-white py-20">
            <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                <div className="text-center">
                    <p className="text-xs font-semibold uppercase tracking-[0.2em] text-brand-600">People</p>
                    <h2 className="mt-3 text-2xl font-semibold tracking-tight text-graphite-900 sm:text-3xl">Know who is involved in the work.</h2>
                    <p className="mx-auto mt-3 max-w-2xl text-sm text-graphite-600 sm:text-base">
                        IOMS connects your real employee data to every permit and every job -- not typed names, real
                        people. Access to create a PTW is individually controlled, never a shared login.
                    </p>
                </div>

                <div className="mx-auto mt-12 flex max-w-4xl flex-wrap items-center justify-center gap-2 sm:gap-3">
                    {chain.map((c, i) => (
                        <div key={c} className="flex items-center gap-2 sm:gap-3">
                            <div className="rounded-lg border border-graphite-200 bg-white px-3 py-2.5 text-sm font-medium text-graphite-700 shadow-card sm:px-4">
                                {c}
                            </div>
                            {i < chain.length - 1 && <ArrowRight className="h-3.5 w-3.5 shrink-0 text-graphite-300" />}
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

/* ------------------------------------------------------------------ */
/* Section: Operational Data                                           */
/* ------------------------------------------------------------------ */
function OperationalData() {
    const sources = ['Man-Hour', 'PPE', 'Waste', 'PTW', 'Incident', 'Inspection', 'CAPA', 'Work Center'];

    return (
        <section className="border-b border-graphite-100 bg-gradient-to-b from-brand-50/50 to-brand-50/20 py-20">
            <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                <SectionHeading eyebrow="Data & Insight" title="Operational records become usable data" subtitle="Every module feeds the same reporting layer -- no separate spreadsheet exports to reconcile." />

                <div className="mt-10 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    {sources.map((s) => (
                        <div key={s} className="rounded-lg border border-graphite-200 bg-white px-3 py-3 text-center text-sm text-graphite-600 shadow-card">
                            {s}
                        </div>
                    ))}
                </div>

                <div className="mt-6 flex justify-center"><ArrowRight className="h-6 w-6 rotate-90 text-graphite-300" /></div>

                <div className="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div className="rounded-lg border border-graphite-200 bg-white px-4 py-4 text-center text-sm font-medium text-graphite-700 shadow-card">Reports</div>
                    <div className="rounded-lg border-2 border-graphite-900 bg-white px-4 py-4 text-center text-sm font-semibold text-graphite-900 shadow-card">Management Insight</div>
                </div>
            </div>
        </section>
    );
}

/* ------------------------------------------------------------------ */
/* Section: Product Preview (mockups, not screenshots)                 */
/* ------------------------------------------------------------------ */
function ProductPreview() {
    return (
        <section className="border-b border-graphite-100 bg-white py-20">
            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <SectionHeading eyebrow="Product" title="What working in IOMS looks like" subtitle="Illustrative previews built from the real IOMS design system -- not stock photography." />

                <div className="mt-12 grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <div className="rounded-xl border border-graphite-200 bg-graphite-50 p-4 shadow-card sm:p-6">
                        <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-graphite-400">HSE Dashboard</p>
                        <MockupDashboard />
                    </div>
                    <div className="rounded-xl border border-graphite-200 bg-graphite-50 p-4 shadow-card sm:p-6">
                        <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-graphite-400">Permit To Work</p>
                        <MockupPtw />
                    </div>
                </div>
            </div>
        </section>
    );
}

function MockupDashboard() {
    const stats = [
        { label: 'Open PTW', value: '—' }, { label: 'Incidents', value: '—' },
        { label: 'Inspections Due', value: '—' }, { label: 'CAPA Open', value: '—' },
    ];
    return (
        <div className="rounded-lg border border-graphite-200 bg-white p-4">
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                {stats.map((s) => (
                    <div key={s.label} className="rounded-md border border-graphite-100 p-2.5 text-center">
                        <p className="text-lg font-semibold text-graphite-300">{s.value}</p>
                        <p className="text-[10px] text-graphite-400">{s.label}</p>
                    </div>
                ))}
            </div>
            <div className="mt-3 h-24 rounded-md border border-dashed border-graphite-200" />
        </div>
    );
}

function MockupPtw() {
    return (
        <div className="rounded-lg border border-graphite-200 bg-white p-4">
            <div className="flex items-center justify-between border-b border-graphite-100 pb-2">
                <span className="text-sm font-semibold text-graphite-700">PTW-2026-XXXXX</span>
                <Badge variant="outline">Pending</Badge>
            </div>
            <div className="mt-3 space-y-2">
                <div className="h-2.5 w-3/4 rounded bg-graphite-100" />
                <div className="h-2.5 w-1/2 rounded bg-graphite-100" />
                <div className="h-2.5 w-2/3 rounded bg-graphite-100" />
            </div>
        </div>
    );
}

function MockupFieldHome() {
    const tiles = [
        { label: 'Create PTW', icon: Flame }, { label: 'My PTW', icon: FileCheck2 },
        { label: "Today's Jobs", icon: Clock }, { label: 'My Tasks', icon: ClipboardCheck },
    ];
    return (
        <div className="rounded-lg border border-graphite-200 bg-white p-4">
            <div className="grid grid-cols-2 gap-2.5">
                {tiles.map((t) => (
                    <div key={t.label} className="flex items-center gap-2 rounded-lg border border-graphite-100 p-2.5">
                        <t.icon className="h-4 w-4 shrink-0 text-brand-500" />
                        <span className="truncate text-xs font-medium text-graphite-600">{t.label}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/* Section: Industries                                                 */
/* ------------------------------------------------------------------ */
function Industries() {
    const industries = [
        { label: 'Shipyard', icon: Ship }, { label: 'Construction', icon: Building },
        { label: 'Manufacturing', icon: Factory }, { label: 'Engineering', icon: Wrench },
        { label: 'Logistics', icon: Truck }, { label: 'Energy', icon: Zap },
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
// v2.27.0 (Public Website & Auth Visual Transformation, Part 9). Purely
// PRESENTATIONAL framing text (not an entitlement, not sourced from the
// Package row) -- matches this pass's own suggested "for smaller teams
// beginning to centralize operations" philosophy. Keyed by `slug` with a
// graceful empty-string fallback for any plan this map doesn't recognize
// (e.g. a future Plan a Platform Admin adds later) -- never blocks
// rendering, never invents copy for an unrecognized plan.
//
// v2.28.0 (Product Experience Transformation, Section 1): the Enterprise
// line previously said "...dan penyesuaian khusus" ("...and custom
// tailoring") -- the one place in the whole app where Enterprise was
// framed as bespoke/custom-built work. IOMS is a standardized SaaS
// product ("build once, improve for everyone"); Enterprise is the most
// COMPLETE tier of the same product, not a custom development track.
// Reworded to what Enterprise actually is under `Package`/`PricingService`
// -- broader module/workspace access, higher max_users/max_ptw_users
// capacity, and full reporting -- without touching is_custom, pricing, or
// entitlement logic itself (out of scope for this pass).
const PLAN_FRAMING = {
    starter: 'Untuk tim yang baru mulai memusatkan operasional mereka.',
    professional: 'Untuk operasional industri yang berkembang dan butuh departemen yang saling terhubung.',
    enterprise: 'Untuk organisasi yang butuh akses penuh, kapasitas lebih besar, dan pelaporan menyeluruh.',
};

function Pricing({ plans }) {
    const [interval, setInterval] = useState('monthly');
    const { version } = usePage().props;
    const supportEmail = version?.support_email;
    // v2.27.0: visual emphasis for the middle plan only -- a pure LAYOUT
    // decision (border/scale/shadow), never a "Most Popular"/"Recommended"
    // text claim, since no such signal exists in the actual Package data
    // (per this pass's own "if unsure, do not invent" instruction).
    const emphasizedIndex = plans && plans.length === 3 ? 1 : -1;

    return (
        <section id="pricing" className="border-b border-graphite-100 bg-white py-20">
            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <SectionHeading eyebrow="Pricing" title="Plans that grow with your operation" subtitle="Pricing is being finalized. The plan structure below reflects our current package architecture." />

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

                        <div className="mt-10 grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3 lg:items-start">
                            {plans.map((plan, i) => {
                                const price = interval === 'monthly' ? plan.monthly : plan.yearly;
                                const emphasized = i === emphasizedIndex;
                                const framing = PLAN_FRAMING[plan.slug] || '';
                                return (
                                    <div
                                        key={plan.id}
                                        className={
                                            emphasized
                                                ? 'relative flex h-full flex-col rounded-2xl border-2 border-brand-500 bg-gradient-to-b from-brand-50/60 to-white p-7 shadow-card-hover lg:-translate-y-2'
                                                : 'flex h-full flex-col rounded-2xl border border-graphite-200 bg-white p-7 shadow-card'
                                        }
                                    >
                                        <h3 className="text-lg font-semibold text-graphite-900">{plan.name}</h3>
                                        {framing && <p className="mt-1.5 text-sm leading-relaxed text-graphite-500">{framing}</p>}

                                        <div className="mt-5">
                                            <p className={emphasized ? 'text-3xl font-bold text-brand-700' : 'text-3xl font-bold text-graphite-900'}>{price.formatted}</p>
                                            {!plan.is_custom && price.amount !== null && (
                                                <p className="text-xs text-graphite-400">per {interval === 'monthly' ? 'bulan' : 'tahun'}</p>
                                            )}
                                        </div>

                                        <ul className="mt-6 flex-1 space-y-2.5 border-t border-graphite-100 pt-5 text-sm text-graphite-600">
                                            <li className="flex items-center gap-2 font-medium text-graphite-800">
                                                <Users className="h-3.5 w-3.5 shrink-0 text-brand-500" /> {plan.max_users ?? 'Unlimited'} User Accounts
                                            </li>
                                            {plan.workspaces.length > 0 && plan.workspaces.map((w) => (
                                                <li key={w} className="flex items-center gap-2"><Check className="h-3.5 w-3.5 shrink-0 text-brand-500" /> {w}</li>
                                            ))}
                                        </ul>

                                        {supportEmail ? (
                                            <Button className="mt-7 w-full" variant={emphasized ? 'default' : 'outline'} asChild>
                                                <a href={`mailto:${supportEmail}`}>Talk to Us</a>
                                            </Button>
                                        ) : (
                                            <Button className="mt-7 w-full" variant={emphasized ? 'default' : 'outline'} asChild>
                                                <Link href={route('login')}>Talk to Us</Link>
                                            </Button>
                                        )}
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
function HowItWorks() {
    const steps = [
        { n: '01', title: 'Set Up Your Workspace', body: 'Your organization, departments, and users are configured.' },
        { n: '02', title: 'Connect Your People & Operations', body: 'Employees, PTW access, and operational modules are set up.' },
        { n: '03', title: 'Run Work Through IOMS', body: 'Field creates PTWs and jobs; HSE reviews and approves.' },
        { n: '04', title: 'Monitor & Improve', body: 'Operational data becomes reports and management insight.' },
    ];

    return (
        <section id="how-it-works" className="border-b border-graphite-100 bg-gradient-to-b from-brand-50/50 to-brand-50/20 py-20">
            <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                <SectionHeading eyebrow="How It Works" title="From setup to insight" />
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
const FAQS = [
    { q: 'What is IOMS?', a: 'IOMS is an Industrial Operations Platform connecting field operations, HSE, workforce data, and operational reporting.' },
    { q: 'Who is IOMS for?', a: 'Industrial companies -- shipyards, construction, manufacturing, engineering, logistics, energy, and industrial services -- that need to manage field work, HSE compliance, and operational data together.' },
    { q: 'Is IOMS only for HSE?', a: 'No. HSE is one of the strongest parts of IOMS, but the platform also covers field operations, people/workforce, and broader operational data.' },
    { q: 'Can field users create PTWs?', a: 'Yes. A field/operations user can be granted individual PTW Access, letting them submit a Permit To Work directly from the Field experience -- HSE still reviews and approves it.' },
    { q: 'How does PTW access work?', a: 'PTW Access is granted per user account, not shared. Each subscription plan includes a limit on how many user accounts can hold PTW Access.' },
    { q: 'Can IOMS be used on mobile?', a: 'Yes. The Field experience is designed mobile-first for use on-site.' },
    { q: 'Is IOMS cloud-based?', a: 'Yes, IOMS is a cloud-hosted, multi-tenant platform.' },
    { q: 'Can different companies use IOMS separately?', a: 'Yes. Each organization\'s data is isolated as its own tenant.' },
];

function Faq() {
    return (
        <section id="faq" className="border-b border-graphite-100 bg-white py-20">
            <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                <SectionHeading eyebrow="FAQ" title="Common questions" />
                <div className="mt-10 divide-y divide-graphite-100 rounded-xl border border-graphite-200">
                    {FAQS.map((item) => (
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
        <section className="bg-graphite-900 py-20 text-white">
            <div className="mx-auto max-w-3xl px-4 text-center sm:px-6 lg:px-8">
                <h2 className="text-2xl font-semibold tracking-tight sm:text-3xl">Ready to connect your operations?</h2>
                <div className="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    <Button size="lg" className="w-full sm:w-auto" asChild><Link href={route('login')}>Get Started <ArrowRight className="h-4 w-4" /></Link></Button>
                    <Button size="lg" variant="outline" className="w-full border-white/20 bg-transparent text-white hover:bg-white/10 sm:w-auto" asChild>
                        <Link href={route('login')}>Login</Link>
                    </Button>
                </div>
            </div>
        </section>
    );
}

/* ------------------------------------------------------------------ */
/* Shared: Section heading                                             */
/* ------------------------------------------------------------------ */
function SectionHeading({ eyebrow, title, subtitle }) {
    return (
        <div className="mx-auto max-w-2xl text-center">
            <p className="text-xs font-semibold uppercase tracking-[0.2em] text-brand-600">{eyebrow}</p>
            <h2 className="mt-3 text-2xl font-semibold tracking-tight text-graphite-900 sm:text-3xl">{title}</h2>
            {subtitle && <p className="mt-3 text-sm text-graphite-600 sm:text-base">{subtitle}</p>}
        </div>
    );
}
