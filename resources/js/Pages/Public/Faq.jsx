import { Head, Link } from '@inertiajs/react';
import { ChevronDown, ArrowRight } from 'lucide-react';
import PublicLayout from '@/Layouts/PublicLayout';
import PublicPageHero from '@/Components/shared/PublicPageHero';
import { Button } from '@/Components/ui/button';

/**
 * v2.51.0 -- the practical questions a B2B buyer asks before paying.
 *
 * Answers come from the server, so the public FAQ and any future
 * in-product help cannot drift apart. Native <details> is used rather than
 * a JS accordion: it is keyboard-accessible and works before hydration.
 */
export default function Faq({ faqs = [], supportEmail }) {
    return (
        <PublicLayout>
            <Head title="FAQ" />

            <PublicPageHero
                eyebrow="FAQ"
                title="Questions worth answering before you buy."
                subtitle="Plans, capacity, payment, activation, data isolation and documents — answered plainly."
                size="sm"
            />

            <section className="bg-graphite-100 py-14 sm:py-20">
                <div className="mx-auto max-w-3xl px-4 sm:px-6">
                    <div className="divide-y divide-steel-100 overflow-hidden rounded-xl border border-steel-200/70 bg-white shadow-panel">
                        {faqs.map((item) => (
                            <details key={item.q} className="group p-5">
                                <summary className="flex cursor-pointer list-none items-center justify-between gap-3 text-sm font-semibold text-navy-900 [&::-webkit-details-marker]:hidden">
                                    {item.q}
                                    <ChevronDown className="h-4 w-4 shrink-0 text-graphite-400 transition-transform group-open:rotate-180" />
                                </summary>
                                <p className="mt-3 text-sm leading-relaxed text-graphite-600">{item.a}</p>
                            </details>
                        ))}
                    </div>

                    <div className="mt-8 rounded-xl border border-steel-200/70 bg-white p-6 text-center shadow-card">
                        <p className="text-sm text-graphite-600">
                            Still have a question?{' '}
                            {supportEmail && (
                                <a href={`mailto:${supportEmail}`} className="font-medium text-brand-700 hover:underline">
                                    Talk to us
                                </a>
                            )}
                        </p>
                        <div className="mt-5 flex flex-col items-center justify-center gap-3 sm:flex-row">
                            <Button asChild>
                                <Link href={route('get-started')}>Get started <ArrowRight className="h-4 w-4" /></Link>
                            </Button>
                            <Button variant="outline" asChild>
                                <Link href={route('pricing')}>View plans</Link>
                            </Button>
                        </div>
                    </div>
                </div>
            </section>
        </PublicLayout>
    );
}
