import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Check, Layers, ShieldCheck, Workflow, FileText } from 'lucide-react';
import PublicLayout from '@/Layouts/PublicLayout';
import PublicPageHero from '@/Components/shared/PublicPageHero';
import { Button } from '@/Components/ui/button';

/**
 * v2.51.0 -- what IOMS actually is.
 *
 * "Platform" was a nav anchor that scrolled to a card grid on the landing
 * page and did nothing anywhere else. It now answers the question a
 * prospect is really asking: what is this, and what does it cover.
 *
 * Every domain listed comes from the server (PublicController::DOMAINS)
 * and maps to a workspace IOMS ships. Nothing here advertises a capability
 * that does not exist.
 */
const PILLARS = [
    {
        icon: Layers,
        title: 'Satu platform, bukan kumpulan aplikasi',
        body: 'Karyawan, aset, izin kerja, stok, dan purchase order mengacu pada data induk yang sama. Catatan yang dibuat di lapangan adalah catatan yang sama yang dilaporkan ke manajemen.',
    },
    {
        icon: ShieldCheck,
        title: 'Terpisah untuk setiap pelanggan',
        body: 'Setiap pelanggan adalah tenant tersendiri. Pemisahan data diterapkan pada lapisan akses data, bukan diserahkan pada setiap query untuk mengingatnya sendiri.',
    },
    {
        icon: Workflow,
        title: 'Persetujuan sudah menyatu',
        body: 'Alur persetujuan, hak akses per peran dan departemen, serta riwayat aktivitas pada setiap catatan — bukan spreadsheet dengan kolom tanda tangan.',
    },
    {
        icon: FileText,
        title: 'Dokumen dengan kop perusahaan Anda',
        body: 'Dokumen operasional dihasilkan sebagai PDF A4 siap cetak dengan identitas perusahaan Anda sendiri, dan data laporan diekspor ke Excel yang sudah tertata.',
    },
];

export default function Platform({ domains = [] }) {
    return (
        <PublicLayout>
            <Head title="Platform" />

            <PublicPageHero
                eyebrow="Platform"
                title="Seluruh operasi Anda dalam satu sistem."
                subtitle="IOMS adalah Industrial Operations Platform: pekerjaan lapangan, persetujuan di baliknya, dan catatan yang dihasilkannya — disatukan, bukan tersebar di formulir, folder, dan spreadsheet."
            />

            <section className="bg-white py-14 sm:py-20">
                <div className="mx-auto max-w-6xl px-4 sm:px-6">
                    <div className="grid gap-5 sm:grid-cols-2">
                        {PILLARS.map((p) => (
                            <div
                                key={p.title}
                                className="rounded-xl border border-steel-200/70 bg-gradient-to-b from-steel-100/70 via-white to-white p-6 shadow-panel"
                            >
                                <span className="flex h-10 w-10 items-center justify-center rounded-[10px] bg-gradient-to-br from-navy-800 to-brand-600 text-white shadow-[0_3px_8px_-3px_rgba(33,102,196,0.38)]">
                                    <p.icon className="h-5 w-5" />
                                </span>
                                <h2 className="mt-4 text-[15px] font-semibold tracking-tight text-navy-900">{p.title}</h2>
                                <p className="mt-2 text-sm leading-relaxed text-graphite-600">{p.body}</p>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            <section className="border-t border-graphite-100 bg-graphite-100 py-14 sm:py-20">
                <div className="mx-auto max-w-6xl px-4 sm:px-6">
                    <div className="mx-auto max-w-2xl text-center">
                        <p className="text-xs font-semibold uppercase tracking-[0.2em] text-brand-600">Cakupan</p>
                        <h2 className="mt-3 text-2xl font-semibold tracking-tight text-navy-900 sm:text-3xl">
                            Domain operasional yang dicakup IOMS
                        </h2>
                        <p className="mt-3 text-sm leading-relaxed text-graphite-600">
                            Domain mana yang dapat dibuka ditentukan oleh paket Anda — platform di baliknya tetap sama.
                        </p>
                    </div>

                    <div className="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {domains.map((d) => (
                            <div key={d.key} className="rounded-xl border border-steel-200/70 bg-white p-5 shadow-card">
                                <h3 className="text-sm font-semibold tracking-tight text-navy-900">{d.name}</h3>
                                <p className="mt-1.5 text-xs leading-relaxed text-graphite-500">{d.summary}</p>
                                <ul className="mt-3 space-y-1.5 border-t border-steel-100 pt-3">
                                    {(d.points ?? []).map((point) => (
                                        <li key={point} className="flex items-start gap-2 text-xs text-graphite-600">
                                            <Check className="mt-0.5 h-3.5 w-3.5 shrink-0 text-success" />
                                            <span>{point}</span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </div>

                    <div className="mt-12 flex flex-col items-center justify-center gap-3 sm:flex-row">
                        <Button asChild>
                            <Link href={route('pricing')}>Lihat paket <ArrowRight className="h-4 w-4" /></Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={route('how-it-works')}>Lihat cara kerjanya</Link>
                        </Button>
                    </div>
                </div>
            </section>
        </PublicLayout>
    );
}
