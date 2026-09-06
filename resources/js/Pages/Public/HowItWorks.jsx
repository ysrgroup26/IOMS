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
                title="Dari data induk sampai laporan manajemen."
                subtitle="IOMS mengikuti satu siklus. Pekerjaan dipusatkan, dijalankan, disetujui oleh pihak yang berwenang, dipantau selama berjalan, lalu menjadi laporan setelah selesai."
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
                            Apa saja yang diperlukan untuk mulai
                        </h2>
                        <p className="mx-auto mt-2 max-w-xl text-sm leading-relaxed text-graphite-600">
                            Anda membuat akun dan perusahaan, mengonfirmasi email, memilih paket, lalu membayar.
                            Workspace Anda disiapkan setelah penyedia pembayaran mengonfirmasi pembayaran — dengan
                            identitas perusahaan Anda sudah terpasang, sehingga dokumen pertama yang Anda buat langsung
                            memakai kop perusahaan sendiri.
                        </p>
                        <div className="mt-6 flex flex-col items-center justify-center gap-3 sm:flex-row">
                            <Button asChild>
                                <Link href={route('get-started')}>Mulai berlangganan <ArrowRight className="h-4 w-4" /></Link>
                            </Button>
                            <Button variant="outline" asChild>
                                <Link href={route('faq')}>Baca FAQ</Link>
                            </Button>
                        </div>
                    </div>
                </div>
            </section>
        </PublicLayout>
    );
}
