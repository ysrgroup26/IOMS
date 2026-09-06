import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowRight, Check, Lock, MousePointerClick, ShieldCheck, Info } from 'lucide-react';
import PublicLayout from '@/Layouts/PublicLayout';
import PublicPageHero from '@/Components/shared/PublicPageHero';
import { Button } from '@/Components/ui/button';

/**
 * v2.53.0 -- the IOMS Sandbox.
 *
 * "See how IOMS works before you buy."
 *
 * IT IS NOT A FREE TRIAL, and this page says so rather than letting a
 * visitor assume otherwise. A free trial hands someone their own empty
 * tenant — the entire provisioning, entitlement and billing machinery,
 * running for people who may never buy, and an empty workspace is a poor
 * demonstration anyway. The Sandbox is one shared, already-populated
 * workspace that everybody walks into.
 *
 * The page is deliberately explicit about what is live and what is
 * locked. A demo that quietly disables half its buttons feels broken; a
 * demo that says up front "these two things are clickable, the rest is
 * read-only" feels honest, and the locked parts become an argument for
 * subscribing rather than a dead end.
 */
const LIVE = [
    'Health, Safety & Environment — raise a Permit To Work, log a safety observation',
    'My Work — the field workspace, with live permits and assigned tasks',
    'Human Resources — browse the employee directory and workforce data',
];

const READ_ONLY = [
    'Dashboard, reports and management views',
    'Project Management records',
    'Generated PDF and Excel documents',
];

const LOCKED = [
    'Procurement, Warehouse and Logistics / PPIC',
    'Assets, Maintenance and Quality Control',
    'Settings, company branding and billing',
];

export default function Sandbox({ available, contactEmail }) {
    const { errors = {} } = usePage().props;
    const { post, processing } = useForm({});

    const enter = (e) => {
        e.preventDefault();
        post(route('sandbox.enter'));
    };

    return (
        <PublicLayout>
            <Head title="Sandbox" />

            <PublicPageHero
                eyebrow="Sandbox"
                title="See how IOMS works before you buy."
                subtitle="Walk into a working IOMS workspace with real operational data already in it — employees, projects, permits and equipment. No sign-up, no card, no trial to cancel."
            >
                <form onSubmit={enter}>
                    <Button size="lg" type="submit" disabled={processing || !available}>
                        {processing ? 'Opening the Sandbox…' : <>Open the Sandbox <ArrowRight className="h-4 w-4" /></>}
                    </Button>
                </form>
            </PublicPageHero>

            <section className="bg-graphite-100 py-14 sm:py-20">
                <div className="mx-auto max-w-5xl px-4 sm:px-6">

                    {errors.sandbox && (
                        <div className="mb-6 rounded-lg border border-danger/20 bg-danger/[0.06] p-4 text-sm text-red-900">
                            {errors.sandbox}
                        </div>
                    )}

                    {! available && (
                        <div className="mb-6 flex items-start gap-2.5 rounded-lg border border-warning/25 bg-warning/[0.07] p-4 text-sm leading-relaxed text-amber-900">
                            <Info className="mt-0.5 h-4 w-4 shrink-0" />
                            <span>
                                The Sandbox has not been set up on this deployment yet.
                                {contactEmail && <> Contact <a href={`mailto:${contactEmail}`} className="font-medium underline">{contactEmail}</a> for a guided walkthrough.</>}
                            </span>
                        </div>
                    )}

                    {/* What a visitor can and cannot do, stated before they go
                        in. A demo that silently disables half its buttons
                        reads as broken software. */}
                    <div className="grid gap-5 md:grid-cols-3">
                        <Panel
                            icon={MousePointerClick}
                            accent="from-navy-800 to-brand-600"
                            title="Try it"
                            body="Fully interactive — create records and see them appear."
                            items={LIVE}
                        />
                        <Panel
                            icon={ShieldCheck}
                            accent="from-steel-500 to-steel-700"
                            title="Explore it"
                            body="Browse freely; changes are switched off so the demo stays intact for the next visitor."
                            items={READ_ONLY}
                        />
                        <Panel
                            icon={Lock}
                            accent="from-graphite-500 to-graphite-700"
                            title="Included with a subscription"
                            body="Available on your own workspace once you subscribe."
                            items={LOCKED}
                        />
                    </div>

                    <div className="mt-8 rounded-xl border border-steel-200/70 bg-white p-6 shadow-panel">
                        <h2 className="text-[15px] font-semibold tracking-tight text-navy-900">
                            This is a demonstration, not a trial
                        </h2>
                        <p className="mt-2 max-w-3xl text-sm leading-relaxed text-graphite-600">
                            The Sandbox is a shared demonstration company with invented data. It is not your workspace,
                            nothing you enter there is kept, and there is nothing to cancel afterwards. When you are
                            ready, subscribing gives you your own isolated workspace with your own company identity —
                            provisioned once payment is confirmed.
                        </p>
                        <div className="mt-5 flex flex-col gap-3 sm:flex-row">
                            <Button asChild>
                                <Link href={route('pricing')}>View plans <ArrowRight className="h-4 w-4" /></Link>
                            </Button>
                            <Button variant="outline" asChild>
                                <Link href={route('get-started')}>Get started</Link>
                            </Button>
                        </div>
                    </div>
                </div>
            </section>
        </PublicLayout>
    );
}

function Panel({ icon: Icon, accent, title, body, items }) {
    return (
        <div className="rounded-xl border border-steel-200/70 bg-gradient-to-b from-steel-100/70 via-white to-white p-6 shadow-panel">
            <span className={`flex h-10 w-10 items-center justify-center rounded-[10px] bg-gradient-to-br ${accent} text-white shadow-[0_3px_8px_-3px_rgba(33,102,196,0.38)]`}>
                <Icon className="h-5 w-5" />
            </span>
            <h2 className="mt-4 text-[15px] font-semibold tracking-tight text-navy-900">{title}</h2>
            <p className="mt-1.5 text-xs leading-relaxed text-graphite-500">{body}</p>
            <ul className="mt-4 space-y-2 border-t border-steel-100 pt-4">
                {items.map((item) => (
                    <li key={item} className="flex items-start gap-2 text-xs text-graphite-600">
                        <Check className="mt-0.5 h-3.5 w-3.5 shrink-0 text-success" />
                        <span>{item}</span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
