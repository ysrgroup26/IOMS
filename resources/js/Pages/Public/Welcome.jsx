import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import PublicLayout from '@/Layouts/PublicLayout';
import Reveal from '@/Components/public/Reveal';
import BlueprintBackdrop from '@/Components/public/BlueprintBackdrop';
import PlatformShowcase from '@/Components/public/PlatformShowcase';
import ConnectedOperations from '@/Components/public/ConnectedOperations';
import HeroComposition from '@/Components/public/HeroComposition';
import Photo from '@/Components/public/Photo';
import FragmentedToConnected from '@/Components/public/FragmentedToConnected';
import StorySection from '@/Components/public/StorySection';
import DomainEditorial from '@/Components/public/DomainEditorial';
import { DOMAIN_STORIES } from '@/Components/public/domainStories';
import OperatingLoop from '@/Components/public/OperatingLoop';
import { cn } from '@/lib/utils';
import { Button } from '@/Components/ui/button';
import {
    ArrowRight, Users, FileCheck2, Flame, Eye, ClipboardCheck, ChevronDown,
    Ship, Building2, Factory, Wrench, Truck, Zap, Check, Plus, Smartphone, Building,
    ShieldCheck, CreditCard,
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
            {/* v2.76.0: this <Head> used to carry its own description and
                og:* tags. Inertia appends those after hydration, so the page
                ended up with TWO meta descriptions and two og:titles that
                disagreed with the server-rendered ones in app.blade.php.
                All search and social metadata now comes from config/seo.php
                via the server; the tab title comes from `seoTitle`. */}
            <Head title="Industrial Operations Platform" />

            <Hero />
            <WhatItOpens />
            <ProblemSection />
            <PlatformOverview />
            <PtwHseStory />
            <FieldExperience />
            <ProductPreview />
            <Industries />
            <Pricing plans={plans} />
            <Trust />
            <HowItWorks steps={steps} />
            <Faq faqs={faqs} />
            <FinalCta />
        </PublicLayout>
    );
}

/* ------------------------------------------------------------------ */
/* Section: Hero                                                       */
/* ------------------------------------------------------------------ */
const INDUSTRIES_STRIP = ['Shipyards', 'Construction', 'Manufacturing', 'Mining', 'Energy & Marine'];

/**
 * v2.85.0 -- the four workspaces IOMS sells, for the hero's right column.
 *
 * Kept as a short one-line form rather than reusing the `domains` server
 * prop: that prop carries the full four-point capability list each domain
 * section renders further down the page, and printing it twice would make
 * the hero a summary of the page instead of an entry into it. The NAMES are
 * the same four `config/plans.php` pins as `operational`, so this list
 * cannot name a fifth workspace or a retired one.
 */
/**
 * v2.88.0 -- the three sectors that have an establishing photograph.
 *
 * Kept beside the section that renders them rather than in domainStories,
 * because these are PLACES IOMS is sold into, not product domains. The alt
 * text describes the scene for anyone who cannot see it, and the label is a
 * separate element over the image rather than text baked into the file.
 */
const FEATURED_INDUSTRIES = [
    {
        name: 'shipyard',
        label: 'Shipyard & Marine',
        alt: 'An aerial view of a working shipyard at sunrise, with vessels in dry dock and gantry cranes along the quay.',
    },
    {
        name: 'construction',
        label: 'Construction',
        alt: 'An aerial view of a large construction site, with tower cranes over a concrete frame.',
    },
    {
        name: 'mining',
        label: 'Mining & Energy',
        alt: 'An open pit mine at sunset, with haul roads cut into terraces and processing plant in the foreground.',
    },
];

const HERO_WORKSPACES = [
    { name: 'Health, Safety & Environment', line: 'Permit To Work, incidents, inspections, HIRADC, JSA, LOTO, PPE and CAPA.' },
    { name: 'People / HRD', line: 'Employee records, competencies and certificates, shifts, rosters, leave and man-hours.' },
    { name: 'Warehouse Logistics', line: 'Material requests, item master, stock per warehouse, goods receipt and movements.' },
    { name: 'Management', line: 'KPI input and history, analytics, Report Center and scheduled reports.' },
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
            {/* v2.59.0: replaces a 56px grid plus two blurred blobs -- the
                same treatment every navy band on this page used, which is
                why three dark sections read as one flat blue field. See
                BlueprintBackdrop for what each layer is doing. */}
            <BlueprintBackdrop variant="hero" />

            <div className="relative mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-20 lg:px-8">
                {/* v2.85.0 -- THE HERO IS NO LONGER CENTERED.
                    A centered stack over a dark surface is the default
                    composition of every generated SaaS landing page, and
                    centered text also forces every line to a new left edge,
                    which is the slowest way to read a paragraph. The
                    headline now sits on a single left margin shared with the
                    eyebrow, the paragraph and the buttons, and the right
                    column carries the four workspaces as a plain list. That
                    asymmetry is what gives the page an axis to hang the rest
                    of its sections on. */}
                <div className="grid grid-cols-1 items-center gap-x-10 gap-y-14 lg:grid-cols-12">
                    <div className="lg:col-span-7">
                        {/* THE PRODUCT DEFINITION IS VISIBLE TEXT (v2.76.0).
                            The eyebrow names the product and its category,
                            the H1 says what it is for, the paragraph names
                            the domains and industries. A search engine and
                            a person skimming should come away with the same
                            sentence, so it lives in the HTML rather than
                            only in metadata. */}
                        <p className="font-mono text-[11px] uppercase tracking-[0.18em] text-steel-300">
                            IOMS / Industrial Operations Platform
                        </p>
                        <h1 className="mt-5 font-display text-[2.3rem] font-semibold leading-[1.04] tracking-[-0.02em] text-white sm:text-[2.9rem] lg:text-[2.75rem] xl:text-[3rem]">
                            Run the whole operation
                            <span className="block text-steel-300">on one record.</span>
                        </h1>

                        <p className="mt-7 max-w-xl text-base leading-relaxed text-navy-300 sm:text-lg">
                            IOMS covers Health, Safety &amp; Environment, People / HRD, Warehouse Logistics and
                            Management reporting in one platform. A permit raised on site, the crew who signed it and
                            the report management reads at month end are the same record, not three systems that no
                            longer agree.
                        </p>

                        {/* One unmistakable primary, one quiet secondary. The
                            secondary is a surface-on-navy rather than a
                            bordered white button, so the pair reads as a
                            hierarchy instead of two buttons competing for
                            the same weight. */}
                        <div className="mt-9 flex flex-col gap-3 sm:flex-row">
                            <Button size="lg" className="w-full sm:w-auto" asChild>
                                <Link href={route('get-started')}>Get Started <ArrowRight className="h-4 w-4" /></Link>
                            </Button>
                            <Button
                                size="lg"
                                variant="ghost"
                                className="w-full border border-white/15 bg-white/[0.06] text-white hover:bg-white/[0.12] hover:text-white sm:w-auto"
                                asChild
                            >
                                <Link href={route('sandbox')}>Open the Sandbox</Link>
                            </Button>
                        </div>

                    </div>

                    {/* v2.87.0 gave the hero a subject: a Permit To Work in
                        the format IOMS actually generates.

                        v2.89.0 gives it an ARGUMENT. The permit now sits
                        across the seam between two photographs, an office
                        above and a dock below, so the hero states the thing
                        IOMS is actually selling: the office plans the work,
                        the field executes it, and one record exists in both
                        places. See HeroComposition for why this is a stacked
                        sequence rather than a split screen. */}
                    <div className="relative lg:col-span-5 lg:-mr-6 xl:-mr-28">
                        <HeroComposition />
                    </div>
                </div>
            </div>
        </section>
    );
}


/**
 * v2.87.0 -- WHAT IOMS OPENS, AND WHO IT IS FOR.
 *
 * This band was one centred sentence. It now carries the two things the hero
 * gave up when the permit took the right column: the four workspaces, and
 * the industries. Both belong here rather than in the hero, which is a
 * single moment and was carrying five competing text blocks.
 *
 * The workspaces are a hairline table, not cards. Four cards would be the
 * generic feature row this page already has too much of; a table is how a
 * specification sheet lists what is in the box, which is the register this
 * audience reads in.
 */
function WhatItOpens() {
    return (
        <section className="border-b border-graphite-100 bg-white py-14 sm:py-16">
            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <Reveal>
                    <p className="max-w-3xl font-display text-xl font-semibold leading-snug tracking-tight text-graphite-900 sm:text-2xl">
                        Departments stop working in separate systems. The operation starts working as one.
                    </p>
                </Reveal>

                {/* v2.87.0: staggered, not because motion is nice but
                    because these four are a list the reader works down.
                    Same gesture as everywhere else, just sequenced. 60ms is
                    short enough that the last row is in before the eye
                    reaches it. */}
                <div className="mt-10 grid grid-cols-1 gap-x-12 sm:grid-cols-2">
                    {HERO_WORKSPACES.map((w, i) => (
                        <Reveal
                            key={w.name}
                            delay={i * 60}
                            className="flex flex-col border-t border-graphite-200 py-5 sm:flex-row sm:gap-6"
                        >
                            <p className="font-display text-[0.95rem] font-semibold tracking-tight text-navy-900 sm:w-52 sm:shrink-0">
                                {w.name}
                            </p>
                            <p className="mt-1.5 text-sm leading-relaxed text-graphite-600 sm:mt-0">{w.line}</p>
                        </Reveal>
                    ))}
                </div>

                <Reveal className="mt-10 flex flex-col gap-4 border-t border-graphite-200 pt-6 sm:flex-row sm:items-baseline sm:justify-between">
                    <p className="text-sm text-graphite-600">
                        Starter begins with HSE. Business runs all four plus the company-wide Dashboard.
                    </p>
                    <p className="font-mono text-[11px] uppercase tracking-[0.14em] text-graphite-400">
                        {INDUSTRIES_STRIP.join('  /  ')}
                    </p>
                </Reveal>
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
                    title="Your departments already have the data. They just don't share it."
                    subtitle="The warehouse cannot see what the yard actually consumed. HSE cannot see which crew the permit covers. Management rebuilds the same report every month from files that disagree."
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
                    subtitle="Every domain below runs in IOMS today, not on a roadmap. They share one set of master data, one approval layer and one reporting layer."
                />

                {/* v2.87.0: the connected-operations diagram opens this
                    section rather than closing the hero. It is an argument
                    about how the platform is put together, so it belongs
                    where that argument is being made, and moving it kept the
                    hero to a single viewport. */}
                {/* Inset on navy rather than recoloured for the light
                    ground. Two reasons: the diagram's whole legibility comes
                    from thin light strokes on a dark surface, and the page
                    had six consecutive white bands through its middle, so a
                    dark object here is doing rhythm work as well as carrying
                    the diagram. */}
                <div className="relative mt-12 overflow-hidden rounded-2xl border border-navy-800 bg-navy-900 px-4 py-10 shadow-[0_24px_60px_-28px_rgba(15,39,71,0.55)] sm:px-8 sm:py-14">
                    <BlueprintBackdrop variant="hero" />
                    <div className="relative">
                        <ConnectedOperations />
                    </div>
                </div>

                {/* v2.87.0 -- THE ZIGZAG IS CAPPED AT TWO.
                    These six domains used to render as six identical
                    left-panel / right-text rows. Six of anything reads as a
                    template, and the eye stops reading at about the third.
                    The first two keep the story layout, because the opening
                    two domains are the ones that carry the argument; the
                    remaining four become a two-column brief, which is a
                    different layout family and is also the right density for
                    content a reader is now skimming rather than studying.
                    Content and capability lists are untouched in
                    domainStories.js, so every claim still maps to a real
                    menu item. */}
                <div className="mx-auto mt-4 max-w-6xl divide-y divide-graphite-100">
                    {DOMAIN_STORIES.slice(0, 2).map((story, i) => (
                        <StorySection
                            key={story.key}
                            eyebrow={story.eyebrow}
                            title={story.title}
                            icon={story.icon}
                            items={story.items}
                            image={story.image}
                            cta={story.cta && {
                                label: story.cta.label,
                                href: story.cta.route ? route(story.cta.route) : story.cta.anchor,
                            }}
                            reverse={i % 2 === 1}
                        >
                            <p>{story.body}</p>
                        </StorySection>
                    ))}
                </div>

                {/* v2.89.0: the four remaining domains used to become a
                    two-column text grid, which left the new workshop,
                    warehouse and management photographs out of the actual
                    story. The editorial sequence gives each scene its own
                    form: a workshop figure, a field handover, a full-bleed
                    warehouse scene, then a management photograph. */}
                <DomainEditorial stories={DOMAIN_STORIES.slice(2)} />
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
                    <h2 className="font-display text-2xl font-semibold tracking-tight sm:text-3xl">One record, from the field to the approval that releases it</h2>
                    <p className="mx-auto mt-3 max-w-2xl text-sm text-graphite-300 sm:text-base">
                        Permit To Work is one worked example of how IOMS connects departments. The same shape applies to
                        a material request, a stock movement or an inspection: one record, the people accountable for
                        it, and the approvals that release it, all visible to management without anyone rekeying it.
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
        <section id="field" className="scroll-mt-20 border-b border-graphite-100 bg-white py-20">
            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div className="grid grid-cols-1 items-center gap-10 lg:grid-cols-2">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-[0.2em] text-brand-600">My Work</p>
                        <h2 className="mt-3 font-display text-2xl font-semibold tracking-tight text-graphite-900 sm:text-3xl">
                            Built for the people doing the work, not only those reporting on it.
                        </h2>
                        <p className="mt-4 text-base text-graphite-600">
                            Foremen, supervisors, technicians and operators land straight in My Work, a compact field
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
        /* v2.87.0 -- THE PRODUCT SITS ON A GROUND, NOT ON THE PAGE.
           The showcase is a white interface that was rendered on a white
           section, so the strongest proof on the page dissolved into its own
           background and read as more page furniture. On a tinted ground with
           a real shadow it reads as a screen: an object being shown, which is
           what proof has to look like. Same treatment as the connected
           operations diagram, for the same reason. */
        <section className="border-b border-graphite-100 bg-gradient-to-b from-steel-50 via-white to-white py-20">
            <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                <SectionHeading
                    title="This is the platform, not a screenshot of one module"
                    subtitle="Switch between the workspaces IOMS actually ships. Built from the real IOMS design system with illustrative operational data, not stock photography."
                />

                <Reveal className="mt-10 rounded-2xl bg-white/70 p-2 shadow-[0_28px_70px_-32px_rgba(15,39,71,0.45)] ring-1 ring-steel-200/70 sm:p-3">
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
        { label: 'Manufacturing' }, { label: 'Engineering & Fabrication' },
        { label: 'Logistics' }, { label: 'Industrial Services' },
    ];

    return (
        <section className="border-b border-graphite-100 bg-gradient-to-b from-brand-50/50 to-brand-50/20 py-16">
            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                {/* v2.76.0 gave this section a heading, because it was the
                    one section whose subject was only an eyebrow.
                    v2.87.0 drops the eyebrow instead: with the heading there,
                    the label was repeating it, and the page was over its
                    eyebrow budget. The headline says what the section is. */}
                <h2 className="max-w-2xl font-display text-2xl font-semibold tracking-tight text-graphite-900 sm:text-3xl">
                    Built for complex industrial operations
                </h2>
                <p className="mt-3 max-w-2xl text-sm leading-relaxed text-graphite-600 sm:text-base">
                    The modules are the same across sectors. What differs is which operational domains a company
                    switches on.
                </p>

                {/* v2.88.0 -- THREE PLACES, NOT SEVEN PILLS.
                    This section was a centred row of seven bordered pills,
                    which is the most generic thing a marketing page can do
                    with a list and said nothing a reader could picture. Three
                    of the photographs are aerial establishing shots of exactly
                    the environments IOMS is sold into, and an establishing
                    shot is the one job a photograph does better than any
                    layout.

                    The remaining four sectors stay as text beneath, because
                    there are only three photographs and inventing a fourth
                    tile would mean either repeating an image or dropping a
                    sector IOMS genuinely serves. */}
                <div className="mt-10 grid grid-cols-1 gap-5 sm:grid-cols-3">
                    {FEATURED_INDUSTRIES.map((ind, i) => (
                        <Reveal key={ind.name} delay={i * 80} className="group relative overflow-hidden rounded-xl">
                            <Photo
                                name={ind.name}
                                alt={ind.alt}
                                ratio="4 / 3"
                                sizes="(min-width: 640px) 33vw, 100vw"
                                imgClassName="transition-transform duration-700 ease-out group-hover:scale-[1.03]"
                            />
                            {/* A scrim only where the label sits, so the top
                                two thirds of the photograph are untouched. */}
                            <div className="pointer-events-none absolute inset-x-0 bottom-0 h-2/5 bg-gradient-to-t from-navy-900/85 to-transparent" />
                            <p className="absolute bottom-0 left-0 right-0 p-4 font-display text-base font-semibold tracking-tight text-white">
                                {ind.label}
                            </p>
                        </Reveal>
                    ))}
                </div>

                <div className="mt-6 flex flex-wrap items-center gap-x-2 gap-y-1.5 text-sm text-graphite-600">
                    <span className="text-graphite-400">Also</span>
                    {industries.map((ind, i) => (
                        <span key={ind.label}>
                            {ind.label}{i < industries.length - 1 ? ',' : ''}
                        </span>
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
    business: 'For an operation that also runs its warehouse and reports to management across several units.',
    // v2.85.0: the `enterprise` entry is gone. Enterprise is retired from
    // sale (is_public = false), so PricingService::publicPlans() never
    // returns it and this landing section could not render it -- a blurb
    // for a plan nobody can buy is copy that only rots.
};
function Pricing({ plans }) {
    const [interval, setInterval] = useState('monthly');
    const { version } = usePage().props;

    return (
        <section id="pricing" className="border-b border-graphite-100 bg-white py-20">
            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <SectionHeading eyebrow="Pricing" title="Plans that grow with your operation" subtitle="One standardized product at three levels of access and capacity. Additional active users are priced per user on every plan, with no implementation fee and no per-customer custom development." />

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

                        {/* v2.87.0 -- THREE CARDS, THREE COLUMNS.
                            The grid asked for four columns at xl, written
                            when the catalogue had four tiers. Enterprise was
                            retired from sale in v2.82.0 and the grid was
                            never narrowed, so at xl the row laid out four
                            columns for three cards and left an empty cell on
                            the right. That is why the section read as
                            left-weighted rather than centred: the composition
                            was balanced around a card that no longer exists.

                            Cards now stretch to a shared height rather than
                            aligning to their tops, which is what lets the
                            three prices sit on one baseline. */}
                        <div className="mx-auto mt-10 grid max-w-6xl grid-cols-1 items-stretch gap-6 md:grid-cols-2 lg:grid-cols-3">
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
                                                ? 'relative flex h-full flex-col rounded-2xl border-2 border-brand-500 bg-gradient-to-b from-brand-50/60 to-white p-6 shadow-card-hover lg:-translate-y-3'
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
                                        {/* v2.87.0 -- THE HEAD IS A FIXED BLOCK.
                                            The three framing lines run to two or
                                            three lines depending on the tier, which
                                            pushed each card's price to a different
                                            height and left the row looking
                                            accidentally staggered. Reserving the
                                            head puts all three prices on one
                                            baseline, which is the comparison the
                                            section exists to make. */}
                                        <div className="min-h-[7.5rem]">
                                            <h3 className="font-display text-lg font-semibold tracking-tight text-graphite-900">{plan.name}</h3>
                                            {/* The tier's one-line answer to "who is this
                                                for", served from config/plans.php beside the
                                                scope it describes so the two cannot drift. */}
                                            {plan.positioning && (
                                                <p className="mt-1 text-xs font-semibold text-brand-700">{plan.positioning}</p>
                                            )}
                                            {framing && <p className="mt-1.5 text-sm leading-relaxed text-graphite-500">{framing}</p>}
                                        </div>

                                        <div className="border-t border-graphite-100 pt-5">
                                            <p className={emphasized ? 'font-display text-[2rem] font-bold tracking-tight text-brand-700' : 'font-display text-[2rem] font-bold tracking-tight text-graphite-900'}>{price.formatted}</p>
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
                                                <Users className="h-3.5 w-3.5 shrink-0 text-brand-500" /> {plan.included_users ? `${plan.included_users} Full Users, ${plan.included_my_work_users ?? 0} My Work Users` : 'Highest user capacity'}
                                            </li>
                                            {/* v2.53.0: capacity is ONE number. PTW Access is a
                                                permission granted inside IOMS, not a sold seat. */}
                                            <li className="flex items-center gap-2">
                                                <FileCheck2 className="h-3.5 w-3.5 shrink-0 text-brand-500" /> {plan.ptw_included_monthly ? `${plan.ptw_included_monthly} PTW documents a month` : 'PTW documents not metered'}
                                            </li>
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

                        {/* v2.85.0 -- THE ALLOWANCE IS NOT A CEILING, SO SAY
                            WHAT PASSING IT COSTS. Each card states the users
                            its plan includes; without this line a reader
                            reasonably concludes the plan stops there. The
                            figure comes from the same `additional_user` the
                            billing layer charges (PricingService), never
                            from copy, so it cannot quote a stale price. */}
                        {plans[0]?.additional_user && (
                            <p className="mt-8 text-center text-sm text-graphite-600">
                                Need more capacity? Full Users are{' '}
                                <strong className="font-semibold text-graphite-900">{plans[0].additional_user.formatted}</strong>{' '}
                                per user per month, My Work Users are{' '}
                                <strong className="font-semibold text-graphite-900">
                                    {plans[0].my_work_pack?.formatted}
                                </strong>{' '}
                                per {plans[0].my_work_pack?.size} users per month, and extra PTW documents start at Rp600 each and do not expire.
                            </p>
                        )}
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
/* Section: Trust                                                      */
/* ------------------------------------------------------------------ */
/**
 * v2.89.0 -- THE SECTION PHASE 07 NEVER SHIPPED.
 *
 * The roadmap's Phase 07 was "Industries + trust + security". Industries
 * shipped; trust did not, and nobody noticed because the page still read as
 * complete. An industrial buyer who has just read a price asks exactly one
 * question next, and the page had no answer anywhere except four scattered
 * FAQ entries below the fold.
 *
 * EVERY CLAIM HERE IS ONE THE PRODUCT ALREADY ENFORCES, and each maps to
 * something in this repository rather than to a security page template:
 * tenant isolation is a global query scope, activation is reachable only
 * from a signature-verified webhook, cancellation deliberately does not
 * delete, and an approval is a stored authorization record rather than a
 * boolean. Nothing about certifications, uptime or compliance badges appears
 * here, because IOMS holds none and this project does not invent them.
 *
 * On navy, and placed immediately after pricing: the question follows the
 * price, and the lower half of the page had gone light-heavy.
 */
function Trust() {
    const guarantees = [
        {
            icon: ShieldCheck,
            title: 'Tenant boundaries apply to data access',
            body: 'Customer records are isolated by tenant-scoped queries, with access checked against the signed-in account and its organization.',
        },
        {
            icon: CreditCard,
            title: 'Payment confirmation comes from the provider',
            body: 'A return page cannot activate a subscription. IOMS updates payment state only after validating the provider callback.',
        },
        {
            icon: FileCheck2,
            title: 'Approval decisions are authorization records',
            body: 'Approver identity and authority are recorded with the decision, then shown on generated approvals where supported.',
        },
        {
            icon: Building2,
            title: 'Subscription lapse does not erase history',
            body: 'Existing operational records remain stored and readable when a subscription moves to its read-only state.',
        },
    ];

    return (
        <section className="relative isolate overflow-hidden border-b border-navy-800 bg-navy-900 py-20 text-white">
            <BlueprintBackdrop />
            <div className="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <SectionHeading
                    onDark
                    title="What a safety record has to be able to promise"
                    subtitle="IOMS holds permits, incident reports, training expiry and audit trails. Four things it guarantees about them, each enforced in the product rather than stated in a policy."
                />

                {/* A hairline row, not four cards. Cards would make these read
                    as features being sold; a specification row reads as
                    properties of the system, which is what they are. */}
                <div className="mt-12 grid grid-cols-1 gap-x-10 sm:grid-cols-2 lg:grid-cols-4">
                    {guarantees.map((g, i) => (
                        <Reveal key={g.title} delay={i * 70} className="border-t border-white/15 pt-5">
                            <g.icon className="h-5 w-5 text-steel-300" aria-hidden="true" />
                            <h3 className="mt-3 font-display text-[15px] font-semibold leading-snug tracking-tight text-white">
                                {g.title}
                            </h3>
                            <p className="mt-2 text-sm leading-relaxed text-navy-300">{g.body}</p>
                        </Reveal>
                    ))}
                </div>

                <p className="mt-10 max-w-3xl text-xs leading-relaxed text-navy-400">
                    IOMS does not claim a certification it does not hold. The full terms, the privacy
                    position and the refund policy are published and linked in the footer.
                </p>
            </div>
        </section>
    );
}

/* ------------------------------------------------------------------ */
/* Section: How It Works                                               */
/* ------------------------------------------------------------------ */
function HowItWorks({ steps = [] }) {
    // v2.52.0: the server's own list (PublicController::HOW_IT_WORKS), so
    // the landing page and /how-it-works can never describe two different
    // products -- they had already drifted while each kept its own copy.
    //
    // v2.64.0: the five stages moved into OperatingLoop, which renders them
    // as one route rather than five cards. The old grid was `lg:grid-cols-4`
    // for FIVE steps, so every large screen orphaned the last one on a
    // second row -- see that component for the concept that replaced it.
    return (
        <section
            id="how-it-works"
            className="relative isolate overflow-hidden border-b border-graphite-100 bg-gradient-to-b from-brand-50/60 via-white to-steel-50/40 py-20 sm:py-24"
        >
            <BlueprintBackdrop variant="light" />

            <div className="relative mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                <SectionHeading
                    title="From master data to management reporting"
                    subtitle="One record travels the whole route: set up once, worked on in the field, approved by whoever is accountable, watched while it runs, and reported on when it closes."
                />

                <div className="mt-14">
                    <OperatingLoop steps={steps} />
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
                <SectionHeading title="Frequently asked questions" />
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
        <section className="relative isolate overflow-hidden bg-navy-900 py-24 text-white sm:py-28">
            {/* v2.88.0 -- THE PAGE ENDS ON THE PLACE IT IS ABOUT.
                This closed on a flat navy block, which read as the page
                running out rather than arriving somewhere. The shipyard
                aerial is the widest, most atmospheric photograph in the set
                and it is the one establishing shot that says INDUSTRIAL
                OPERATIONS without a caption, so it belongs at the close
                where the reader is deciding.

                Full-bleed and heavily scrimmed, because here the photograph
                is a GROUND for the call to action rather than the subject.
                That is the opposite job from the hero, where the image is
                beside the copy and keeps its detail, and it is why the two
                treatments are deliberately not the same. */}
            <div className="absolute inset-0 -z-10" aria-hidden="true">
                <Photo
                    name="shipyard"
                    alt=""
                    ratio="auto"
                    className="h-full w-full"
                    position="center 55%"
                    sizes="100vw"
                />
                <div className="absolute inset-0 bg-navy-900/80" />
                <div className="absolute inset-0 bg-gradient-to-t from-navy-900 via-navy-900/40 to-navy-900" />
            </div>
            <BlueprintBackdrop />
            <div className="mx-auto max-w-3xl px-4 text-center sm:px-6 lg:px-8">
                <h2 className="font-display text-2xl font-semibold tracking-tight sm:text-3xl">Ready to connect your operation?</h2>
                <p className="mx-auto mt-3 max-w-xl text-sm text-graphite-300 sm:text-base">
                    Try the Sandbox first, or choose a plan and register your company. Your workspace is provisioned
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
/**
 * v2.85.0 -- LEFT-ALIGNED, MONO EYEBROW, DISPLAY TITLE.
 *
 * Every section on this page was centred, which gave a long page a single
 * repeating rhythm and no axis. The heading now sits on the page's left
 * margin, so the sections below it can be asymmetric without the heading
 * fighting them, and the eyebrow reads as an instrument label rather than
 * as small bold marketing text.
 *
 * `onDark` exists because two sections sit on navy and previously passed
 * their own text colours around this component's defaults.
 */
function SectionHeading({ eyebrow, title, subtitle, onDark = false }) {
    return (
        <Reveal className="max-w-3xl">
            {/* v2.87.0: OPTIONAL, and used sparingly.
                Every section on this page carried one of these, which gave a
                twelve-section page one repeating LABEL / Headline / body
                rhythm and made the whole thing read as generated. The label
                now appears only where a genuinely new chapter opens. */}
            {eyebrow && (
                <p className={cn(
                    'mb-4 font-mono text-[11px] uppercase tracking-[0.18em]',
                    onDark ? 'text-steel-300' : 'text-brand-600'
                )}>
                    {eyebrow}
                </p>
            )}
            <h2 className={cn(
                'font-display text-[1.75rem] font-semibold leading-[1.12] tracking-[-0.02em] sm:text-[2.25rem]',
                onDark ? 'text-white' : 'text-graphite-900'
            )}>
                {title}
            </h2>
            {subtitle && (
                <p className={cn(
                    'mt-4 max-w-2xl text-sm leading-relaxed sm:text-base',
                    onDark ? 'text-navy-300' : 'text-graphite-600'
                )}>
                    {subtitle}
                </p>
            )}
        </Reveal>
    );
}
