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

// v2.27.0 (Public Website & Auth Visual Transformation, Part 4/7). The
// platform-visualization node set -- 8 real domains around a central
// "IOMS" hub, each cross-checked against `PLATFORM_AREAS` /
// `resources/js/lib/workspaces.js` below (same source of truth every
// other section on this page already uses) so this visual never implies
// a capability that doesn't exist. Positions are plain percentage
// coordinates on a 100x100 circle (top, going clockwise) -- no JS
// trig/animation library, just static numbers computed once.
const ORBIT_NODES = [
    { label: 'Health, Safety & Environment', icon: ShieldCheck, x: 50, y: 10 },
    { label: 'Human Resources', icon: Users, x: 84, y: 24 },
    { label: 'Operations', icon: Cog, x: 90, y: 50 },
    { label: 'Warehouse', icon: Warehouse, x: 84, y: 76 },
    { label: 'Procurement', icon: ShoppingCart, x: 50, y: 90 },
    { label: 'Logistics', icon: Truck, x: 16, y: 76 },
    { label: 'Project Management', icon: FolderKanban, x: 10, y: 50 },
    { label: 'Reports', icon: LineChart, x: 16, y: 24 },
];

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
            <div
                className="pointer-events-none absolute inset-0 -z-10 opacity-[0.16]"
                aria-hidden="true"
                style={{
                    backgroundImage:
                        'linear-gradient(to right, rgba(255,255,255,0.10) 1px, transparent 1px), linear-gradient(to bottom, rgba(255,255,255,0.10) 1px, transparent 1px)',
                    backgroundSize: '56px 56px',
                    maskImage: 'linear-gradient(to bottom, black, transparent 90%)',
                }}
            />
            <div className="pointer-events-none absolute -right-40 -top-40 -z-10 h-[38rem] w-[38rem] rounded-full bg-steel-500 opacity-[0.15] blur-3xl" aria-hidden="true" />
            <div className="pointer-events-none absolute -bottom-56 -left-40 -z-10 h-[34rem] w-[34rem] rounded-full bg-brand-600 opacity-[0.12] blur-3xl" aria-hidden="true" />

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

                {/* The platform visualization: a central IOMS hub with eight
                    real product domains around it. Desktop-only -- absolute
                    nodes on a percentage circle cannot reflow safely to a
                    narrow viewport, so mobile gets the plain wrap-grid below
                    rather than a shrunken copy of this same layout. */}
                <div className="relative mx-auto mt-20 hidden aspect-square max-w-xl lg:block">
                    <svg className="absolute inset-0 h-full w-full" viewBox="0 0 100 100" aria-hidden="true">
                        {ORBIT_NODES.map((n) => (
                            <line key={n.label} x1="50" y1="50" x2={n.x} y2={n.y} stroke="rgb(255 255 255 / 0.16)" strokeWidth="0.35" />
                        ))}
                    </svg>

                    <div className="absolute left-1/2 top-1/2 flex h-32 w-32 -translate-x-1/2 -translate-y-1/2 flex-col items-center justify-center rounded-full border border-white/15 bg-navy-800/80 shadow-panel backdrop-blur-sm">
                        <div className="flex h-14 w-14 items-center justify-center rounded-full bg-white/10 text-steel-200 ring-1 ring-inset ring-white/15">
                            <Sparkles className="h-6 w-6" />
                        </div>
                        <p className="mt-2 text-sm font-bold tracking-tight text-white">IOMS</p>
                    </div>

                    {ORBIT_NODES.map((n) => (
                        <div
                            key={n.label}
                            className="absolute flex -translate-x-1/2 -translate-y-1/2 flex-col items-center gap-1.5 rounded-xl border border-white/10 bg-white/[0.07] px-3 py-2.5 backdrop-blur-sm"
                            style={{ left: `${n.x}%`, top: `${n.y}%` }}
                        >
                            <n.icon className="h-4 w-4 text-steel-300" />
                            <span className="whitespace-nowrap text-[11px] font-medium text-steel-100">{n.label}</span>
                        </div>
                    ))}
                </div>

                <div className="mx-auto mt-16 grid max-w-md grid-cols-2 gap-3 sm:grid-cols-4 lg:hidden">
                    {ORBIT_NODES.map((n) => (
                        <div key={n.label} className="flex flex-col items-center gap-1.5 rounded-xl border border-white/10 bg-white/[0.06] px-3 py-3 text-center">
                            <n.icon className="h-4 w-4 text-steel-300" />
                            <span className="text-[11px] font-medium leading-tight text-steel-100">{n.label}</span>
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
    const fragments = ['Excel per department', 'WhatsApp approvals', 'Paper permits', 'Separate HSE records', 'Manual stock notes', 'Reports rebuilt by hand'];

    return (
        <section className="border-b border-graphite-100 bg-gradient-to-b from-brand-50/50 to-brand-50/20 py-20">
            <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                <div className="text-center">
                    <h2 className="text-2xl font-semibold tracking-tight text-graphite-900 sm:text-3xl">
                        Your departments already have the data. They just don't share it.
                    </h2>
                    <p className="mt-3 text-base text-graphite-600">
                        Procurement cannot see what the yard actually consumed. HSE cannot see which job the permit
                        belongs to. Management rebuilds the same report every month.
                    </p>
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
                    <p className="mt-2 text-xl font-semibold text-graphite-900 sm:text-2xl">One connected operation</p>
                </div>
            </div>
        </section>
    );
}

/* ------------------------------------------------------------------ */
/* Section: Platform Overview                                          */
/* ------------------------------------------------------------------ */
const PLATFORM_AREAS = [
    { title: 'Project Management', icon: FolderKanban, items: ['Projects & milestones', 'Manpower assignment', 'Daily reports', 'Progress records'] },
    { title: 'Operations', icon: Cog, items: ['Work Center', 'Tasks & follow-up', 'Man-Hour', 'Activity timeline on every record'] },
    { title: 'Human Resources', icon: Users, items: ['Employee master data', 'Competency & certificate expiry', 'Shifts & rosters', 'Contractors & visitors'] },
    { title: 'Procurement & Warehouse', icon: ShoppingCart, items: ['Purchase Requisition (FPB)', 'RFQ & vendor comparison', 'Purchase Order', 'Goods receipt & stock movement'] },
    { title: 'Health, Safety & Environment', icon: ShieldCheck, items: ['Permit To Work', 'Incidents & observations', 'Inspections, JSA, HIRADC', 'LOTO, gas test, PPE, CAPA'] },
    { title: 'Management & Reporting', icon: BarChart3, items: ['KPI records', 'Report Center', 'Scheduled reports', 'PDF & Excel on your letterhead'] },
];

function PlatformOverview() {
    return (
        <section id="platform" className="border-b border-graphite-100 bg-white py-20">
            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <SectionHeading
                    eyebrow="Platform"
                    title="One platform, every operational domain"
                    subtitle="Every domain below runs in IOMS today — not a roadmap. They share one set of master data, one approval layer and one reporting layer."
                />

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
        'Pengguna lapangan membuat PTW',
        'Requester tercatat otomatis dari akun yang login',
        'Penanggung Jawab Pekerjaan (opsional)',
        'Workforce dari direktori karyawan (opsional)',
        'Diajukan',
        'Ditinjau oleh Health, Safety & Environment',
        'HIRADC / JSA / Gas Test bila diperlukan',
        'Disetujui',
        'Status izin terlihat kembali di lapangan',
    ];

    return (
        <section id="solutions" className="relative isolate overflow-hidden border-b border-navy-800 bg-navy-900 py-20 text-white">
            <div className="pointer-events-none absolute -right-32 -top-32 -z-10 h-[30rem] w-[30rem] rounded-full bg-steel-500 opacity-[0.13] blur-3xl" aria-hidden="true" />
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
                    <div className="rounded-xl border border-graphite-200 bg-graphite-50 p-4 shadow-card sm:p-6">
                        <MockupFieldHome />
                    </div>
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
                <SectionHeading eyebrow="Product" title="What working in IOMS looks like" subtitle="Illustrative screens built from the real IOMS design system, with sample operational data — not stock photography." />

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
    // v2.27.0: visual emphasis for the middle plan only -- a pure LAYOUT
    // decision (border/scale/shadow), never a "Most Popular"/"Recommended"
    // text claim, since no such signal exists in the actual Package data
    // (per this pass's own "if unsure, do not invent" instruction).
    const emphasizedIndex = plans && plans.length === 3 ? 1 : -1;

    return (
        <section id="pricing" className="border-b border-graphite-100 bg-white py-20">
            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <SectionHeading eyebrow="Pricing" title="Plans that grow with your operation" subtitle="One standardized product at three levels of access and capacity. No hidden implementation fee, and no per-company custom development." />

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
                                                <Users className="h-3.5 w-3.5 shrink-0 text-brand-500" /> {plan.max_users ? `${plan.max_users} user accounts` : 'Highest user capacity'}
                                            </li>
                                            {/* v2.53.0: capacity is ONE number. PTW Access is a
                                                permission granted inside IOMS, not a sold seat. */}
                                            <li className="flex items-center gap-2">
                                                <Building2 className="h-3.5 w-3.5 shrink-0 text-brand-500" /> {plan.max_companies ? `${plan.max_companies} Operating Unit${plan.max_companies > 1 ? 's' : ''}` : 'Multiple Operating Units'}
                                            </li>
                                            {plan.workspaces.length > 0 && plan.workspaces.map((w) => (
                                                <li key={w} className="flex items-center gap-2"><Check className="h-3.5 w-3.5 shrink-0 text-brand-500" /> {w}</li>
                                            ))}
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
            <div className="pointer-events-none absolute -left-32 -bottom-40 -z-10 h-[28rem] w-[28rem] rounded-full bg-brand-600 opacity-[0.14] blur-3xl" aria-hidden="true" />
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
function SectionHeading({ eyebrow, title, subtitle }) {
    return (
        <div className="mx-auto max-w-2xl text-center">
            <p className="text-xs font-semibold uppercase tracking-[0.2em] text-brand-600">{eyebrow}</p>
            <h2 className="mt-3 text-2xl font-semibold tracking-tight text-graphite-900 sm:text-3xl">{title}</h2>
            {subtitle && <p className="mt-3 text-sm text-graphite-600 sm:text-base">{subtitle}</p>}
        </div>
    );
}
