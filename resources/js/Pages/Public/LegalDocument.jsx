import { Head, Link } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import { Mail } from 'lucide-react';

/**
 * v2.55.0 -- one renderer for all three public policy documents.
 *
 * Replaces `Public/Legal.jsx`, which rendered "This page is being prepared"
 * for both Terms and Privacy. The content now comes from
 * App\Support\LegalDocuments, so this file holds typography and nothing
 * else -- and the three documents cannot drift apart visually.
 *
 * Deliberately restrained: a legal document earns trust by being legible
 * and complete, not by being decorated. Measured column, real heading
 * hierarchy, generous line height, no cards.
 */
export default function LegalDocument({ title, summary, sections, operator, effectiveDate, emails }) {
    const documents = [
        { label: 'Terms of Service', route: 'legal.terms' },
        { label: 'Privacy Policy', route: 'legal.privacy' },
        { label: 'Refund & Cancellation', route: 'legal.refunds' },
    ];

    return (
        <PublicLayout>
            <Head title={title} />

            <div className="border-b border-graphite-100 bg-graphite-50/60">
                <div className="mx-auto max-w-3xl px-4 py-14 sm:px-6 lg:px-8">
                    <p className="text-xs font-semibold uppercase tracking-[0.2em] text-graphite-400">Legal</p>
                    <h1 className="mt-3 text-3xl font-semibold tracking-tight text-graphite-900 sm:text-4xl">{title}</h1>
                    <p className="mt-3 text-base leading-relaxed text-graphite-600">{summary}</p>

                    {/* Printed only when configured -- IOMS never states an
                        operator or an effective date it does not have. */}
                    <dl className="mt-6 flex flex-wrap gap-x-8 gap-y-2 text-xs text-graphite-500">
                        {operator && (
                            <div>
                                <dt className="font-semibold uppercase tracking-wide text-graphite-400">Operator</dt>
                                <dd className="mt-0.5 text-graphite-700">{operator}</dd>
                            </div>
                        )}
                        {effectiveDate && (
                            <div>
                                <dt className="font-semibold uppercase tracking-wide text-graphite-400">Berlaku sejak</dt>
                                <dd className="mt-0.5 text-graphite-700">{effectiveDate}</dd>
                            </div>
                        )}
                    </dl>
                </div>
            </div>

            <div className="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:px-8">
                <nav aria-label="Legal documents" className="flex flex-wrap gap-2 border-b border-graphite-100 pb-8">
                    {documents.map((d) => (
                        <Link
                            key={d.route}
                            href={route(d.route)}
                            className={
                                'rounded-full border px-3.5 py-1.5 text-xs font-medium transition-colors '
                                + (d.label === title || (title.startsWith(d.label) )
                                    ? 'border-brand-200 bg-brand-50 text-brand-700'
                                    : 'border-graphite-200 text-graphite-600 hover:border-graphite-300 hover:text-graphite-900')
                            }
                        >
                            {d.label}
                        </Link>
                    ))}
                </nav>

                <article className="mt-10 space-y-9">
                    {sections.map((section) => (
                        <section key={section.heading}>
                            <h2 className="text-base font-semibold tracking-tight text-graphite-900">{section.heading}</h2>
                            <div className="mt-3 space-y-3">
                                {section.body.map((paragraph, i) => (
                                    <p key={i} className="text-sm leading-relaxed text-graphite-600">{paragraph}</p>
                                ))}
                            </div>
                        </section>
                    ))}
                </article>

                <div className="mt-12 rounded-xl border border-graphite-200 bg-graphite-50 p-5">
                    <p className="text-sm font-medium text-graphite-800">Ada pertanyaan tentang dokumen ini?</p>
                    <div className="mt-3 flex flex-wrap gap-x-6 gap-y-2 text-sm">
                        {emails?.support && (
                            <a href={`mailto:${emails.support}`} className="flex items-center gap-1.5 text-brand-700 hover:underline">
                                <Mail className="h-3.5 w-3.5" /> {emails.support}
                            </a>
                        )}
                        {emails?.billing && (
                            <a href={`mailto:${emails.billing}`} className="flex items-center gap-1.5 text-brand-700 hover:underline">
                                <Mail className="h-3.5 w-3.5" /> {emails.billing}
                            </a>
                        )}
                        <Link href={route('contact')} className="text-graphite-600 hover:text-graphite-900">
                            Halaman Contact
                        </Link>
                    </div>
                </div>
            </div>
        </PublicLayout>
    );
}
