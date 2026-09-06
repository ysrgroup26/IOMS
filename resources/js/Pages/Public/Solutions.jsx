import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Check } from 'lucide-react';
import PublicLayout from '@/Layouts/PublicLayout';
import PublicPageHero from '@/Components/shared/PublicPageHero';
import { Button } from '@/Components/ui/button';

/**
 * v2.51.0 -- Solutions, by operational domain.
 *
 * The nav item used to scroll to the PTW/HSE story on the landing page,
 * which is one workflow, not a solutions overview. This page answers
 * "does IOMS cover my part of the operation?" domain by domain.
 *
 * Content comes from the server so this page and /platform cannot drift
 * apart, and so every claim stays reviewable in one list.
 */
const INDUSTRIES = [
    'Galangan Kapal & Marine',
    'Konstruksi & Sipil',
    'Minyak, Gas & Energi',
    'Manufaktur',
    'Pertambangan & Mineral',
    'Engineering & Fabrikasi',
    'Logistik & Transportasi',
    'Jasa Industri',
];

export default function Solutions({ domains = [] }) {
    return (
        <PublicLayout>
            <Head title="Solutions" />

            <PublicPageHero
                eyebrow="Solutions"
                title="Dibangun mengikuti cara operasi industri berjalan."
                subtitle="Setiap domain di bawah ini sudah dicakup IOMS hari ini. Semuanya berbagi satu data induk, satu lapisan persetujuan, dan satu lapisan pelaporan — sehingga pekerjaan yang melintasi departemen tidak perlu melintasi sistem."
            />

            <section className="bg-white py-14 sm:py-20">
                <div className="mx-auto max-w-5xl px-4 sm:px-6">
                    <div className="space-y-4">
                        {domains.map((d, i) => (
                            <div
                                key={d.key}
                                className="rounded-xl border border-steel-200/70 bg-gradient-to-b from-steel-100/60 via-white to-white p-6 shadow-card sm:flex sm:gap-6"
                            >
                                <div className="sm:w-56 sm:shrink-0">
                                    <span className="text-[11px] font-mono text-graphite-400">
                                        {String(i + 1).padStart(2, '0')}
                                    </span>
                                    <h2 className="mt-1 text-base font-semibold tracking-tight text-navy-900">{d.name}</h2>
                                </div>
                                <div className="mt-3 min-w-0 flex-1 sm:mt-0">
                                    <p className="text-sm leading-relaxed text-graphite-600">{d.summary}</p>
                                    <ul className="mt-3 grid gap-1.5 sm:grid-cols-2">
                                        {(d.points ?? []).map((point) => (
                                            <li key={point} className="flex items-start gap-2 text-xs text-graphite-600">
                                                <Check className="mt-0.5 h-3.5 w-3.5 shrink-0 text-success" />
                                                <span>{point}</span>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            <section className="border-t border-graphite-100 bg-graphite-100 py-14 sm:py-16">
                <div className="mx-auto max-w-4xl px-4 text-center sm:px-6">
                    <p className="text-xs font-semibold uppercase tracking-[0.2em] text-brand-600">Industri</p>
                    <h2 className="mt-3 text-2xl font-semibold tracking-tight text-navy-900">
                        Industri yang menggunakan IOMS
                    </h2>
                    <p className="mx-auto mt-3 max-w-xl text-sm leading-relaxed text-graphite-600">
                        IOMS dibangun untuk operasi industri secara umum, bukan satu sektor tertentu. Modulnya sama di
                        semua industri; yang berbeda adalah domain mana yang diaktifkan perusahaan Anda.
                    </p>

                    <div className="mt-8 flex flex-wrap justify-center gap-2">
                        {INDUSTRIES.map((name) => (
                            <span
                                key={name}
                                className="rounded-full border border-steel-200 bg-white px-3.5 py-1.5 text-xs font-medium text-navy-800 shadow-card"
                            >
                                {name}
                            </span>
                        ))}
                    </div>

                    <div className="mt-10 flex flex-col items-center justify-center gap-3 sm:flex-row">
                        <Button asChild>
                            <Link href={route('get-started')}>Mulai berlangganan <ArrowRight className="h-4 w-4" /></Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={route('pricing')}>Bandingkan paket</Link>
                        </Button>
                    </div>
                </div>
            </section>
        </PublicLayout>
    );
}
