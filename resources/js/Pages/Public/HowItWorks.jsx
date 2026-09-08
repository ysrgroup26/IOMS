import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Database, Hammer, Stamp, Activity, TrendingUp } from 'lucide-react';
import PublicLayout from '@/Layouts/PublicLayout';
import PublicPageHero from '@/Components/shared/PublicPageHero';
import { Button } from '@/Components/ui/button';

/**
 * v2.51.0 -- Centralize -> Operate -> Approve -> Monitor -> Improve.
 *
 * The operational loop IOMS is shaped around, stated once at a level a
 * prospect can evaluate. Steps come from the server so this page and the
 * landing page's shorter version cannot describe two different products.
 */
const ICONS = [Database, Hammer, Stamp, Activity, TrendingUp];

export default function HowItWorks({ steps = [] }) {
    return (
        <PublicLayout>
            <Head title="How It Works" />

            <PublicPageHero
                eyebrow="How It Works"
                title="From master data to management reporting."
                subtitle="IOMS follows one cycle. Work is centralized, carried out, approved by whoever is accountable for it, watched while it runs, and reported on once it closes — and what that reporting shows is what the next cycle starts from."
            />

            <section className="bg-white py-14 sm:py-20">
                <div className="mx-auto max-w-4xl px-4 sm:px-6">
                    <ol className="space-y-4">
                        {steps.map((s, i) => {
                            const Icon = ICONS[i] || Database;

                            return (
                                <li
                                    key={s.step}
                                    className="flex gap-4 rounded-xl border border-steel-200/70 bg-gradient-to-b from-steel-100/60 via-white to-white p-6 shadow-card"
                                >
                                    <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-navy-800 to-brand-600 text-white shadow-[0_3px_8px_-3px_rgba(33,102,196,0.38)]">
                                        <Icon className="h-5 w-5" />
                                    </span>
                                    <div className="min-w-0">
                                        <p className="text-[11px] font-mono text-graphite-400">{s.step}</p>
                                        <h2 className="mt-0.5 text-base font-semibold tracking-tight text-navy-900">{s.title}</h2>
                                        <p className="mt-1.5 text-sm leading-relaxed text-graphite-600">{s.body}</p>
                                    </div>
                                </li>
                            );
                        })}
                    </ol>

                    <div className="mt-10 rounded-xl border border-steel-200/70 bg-white p-6 text-center shadow-card">
                        <h2 className="text-[15px] font-semibold tracking-tight text-navy-900">
                            What it takes to start
                        </h2>
                        <p className="mx-auto mt-2 max-w-xl text-sm leading-relaxed text-graphite-600">
                            You create your account and company, confirm your email, choose a plan and pay. Your
                            workspace is provisioned once the payment provider confirms the payment — with your company
                            identity already in place, so the first document you generate carries your own letterhead.
                        </p>
                        <div className="mt-6 flex flex-col items-center justify-center gap-3 sm:flex-row">
                            <Button asChild>
                                <Link href={route('get-started')}>Get Started <ArrowRight className="h-4 w-4" /></Link>
                            </Button>
                            <Button variant="outline" asChild>
                                <Link href={route('faq')}>Read the FAQ</Link>
                            </Button>
                        </div>
                    </div>
                </div>
            </section>
        </PublicLayout>
    );
}
