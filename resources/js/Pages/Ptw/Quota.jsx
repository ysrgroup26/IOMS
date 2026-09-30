import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import EmptyState from '@/Components/shared/EmptyState';
import { Button } from '@/Components/ui/button';
import { FileCheck2, ShieldAlert, Plus, ArrowRight } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * v2.86.0 -- PTW document quota.
 *
 * TWO POOLS ARE SHOWN AS TWO POOLS. The approved model is explicit that
 * included and purchased quota must not collapse into one remaining figure,
 * and that is a presentation rule as much as a storage one: a customer
 * deciding whether to buy needs to know that the 40 they are looking at is
 * 10 that expires this month and 30 that does not.
 *
 * WHO SEES WHAT. Anyone who may raise a permit can read this page, because
 * being refused at the moment of writing a permit with no way to find out
 * why is the worst version of a quota feature. Only an administrator sees
 * the purchase controls, and the server enforces that independently.
 */
export default function PtwQuota({ balance, packs = [], recent = [], canPurchase }) {
    const { flash } = usePage().props;
    const [buying, setBuying] = useState(null);

    const purchase = (pack) => {
        setBuying(pack.key);
        router.post(route('permits-to-work.quota.purchase'), { pack: pack.key }, {
            preserveScroll: true,
            onFinish: () => setBuying(null),
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="PTW Document Quota" />

            <PageHeader
                title="PTW Document Quota"
                subtitle="Sisa dokumen PTW perusahaan Anda, dan cara menambahnya."
                icon={FileCheck2}
            />

            {flash?.error && (
                <div className="mb-4 rounded-xl border border-danger/30 bg-danger-light px-4 py-3 text-sm text-danger">
                    {flash.error}
                </div>
            )}

            {!balance?.metered ? (
                <EmptyState
                    icon={FileCheck2}
                    title="PTW documents are not metered on this plan"
                    description="Paket perusahaan Anda tidak membatasi jumlah dokumen PTW."
                />
            ) : (
                <>
                    {/* The exhausted state comes first, because a reader who
                        cannot create a permit is here to find out why, and
                        should not have to read three cards to discover it. */}
                    {balance.total === 0 && (
                        <div className="mb-5 flex items-start gap-3 rounded-xl border border-danger/30 bg-danger-light px-4 py-3.5">
                            <ShieldAlert className="mt-0.5 h-5 w-5 shrink-0 text-danger" />
                            <div>
                                <p className="text-sm font-semibold text-danger">Kuota dokumen PTW habis</p>
                                <p className="mt-1 text-sm leading-relaxed text-graphite-700">
                                    PTW baru tidak dapat dibuat sampai kuota ditambah. Pekerjaan yang sudah berjalan,
                                    My Work, dan PTW yang sudah ada tetap dapat diakses seperti biasa.
                                </p>
                            </div>
                        </div>
                    )}

                    {balance.low && balance.total > 0 && (
                        <div className="mb-5 flex items-start gap-3 rounded-xl border border-warning/30 bg-warning-light px-4 py-3.5">
                            <ShieldAlert className="mt-0.5 h-5 w-5 shrink-0 text-warning" />
                            <p className="text-sm leading-relaxed text-graphite-700">
                                <span className="font-semibold text-graphite-900">Kuota PTW menipis.</span>{' '}
                                Tersisa {balance.total} dokumen. Tambah kuota sebelum habis agar pekerjaan lapangan
                                tidak tertunda.
                            </p>
                        </div>
                    )}

                    <div className="grid gap-4 sm:grid-cols-3">
                        <Pool
                            label="Included this period"
                            value={balance.included}
                            of={balance.included_allowance}
                            hint="Berlaku sampai akhir periode dan tidak dibawa ke periode berikutnya."
                            tone={balance.included === 0 ? 'spent' : 'default'}
                        />
                        <Pool
                            label="Purchased"
                            value={balance.purchased}
                            hint="Tidak hangus. Terbawa ke periode berikutnya sampai terpakai."
                            tone="purchased"
                        />
                        <Pool
                            label="Total available"
                            value={balance.total}
                            hint="Dipakai mulai dari kuota bulanan, baru kemudian kuota yang dibeli."
                            tone={balance.total === 0 ? 'spent' : 'strong'}
                        />
                    </div>

                    {canPurchase && (
                        <section className="mt-8">
                            <h2 className="text-base font-semibold tracking-tight text-graphite-900">Tambah kuota PTW</h2>
                            <p className="mt-1 text-sm text-graphite-600">
                                Kuota yang dibeli tidak hangus di akhir periode. Kuota ditambahkan setelah pembayaran
                                dikonfirmasi.
                            </p>

                            <div className="mt-4 grid gap-4 sm:grid-cols-3">
                                {packs.map((pack) => (
                                    <div
                                        key={pack.key}
                                        className="flex flex-col rounded-xl border border-graphite-200 bg-white p-5 shadow-card"
                                    >
                                        <p className="font-mono text-[11px] uppercase tracking-[0.16em] text-graphite-400">
                                            Top up
                                        </p>
                                        <p className="mt-2 text-2xl font-semibold tracking-tight text-navy-900">
                                            {pack.documents} PTW
                                        </p>
                                        <p className="mt-1 text-sm font-semibold text-graphite-900">{pack.formatted}</p>
                                        <p className="mt-0.5 text-xs text-graphite-500">{pack.per_document} per dokumen</p>
                                        <Button
                                            className="mt-5 w-full"
                                            variant="outline"
                                            disabled={buying !== null}
                                            onClick={() => purchase(pack)}
                                        >
                                            {buying === pack.key ? 'Membuat tagihan...' : <>Beli <Plus className="h-4 w-4" /></>}
                                        </Button>
                                    </div>
                                ))}
                            </div>

                            <p className="mt-4 text-sm text-graphite-600">
                                Butuh kapasitas lebih besar secara rutin?{' '}
                                <Link href={route('subscription.billing')} className="font-medium text-brand-700 hover:underline">
                                    Tingkatkan paket <ArrowRight className="inline h-3.5 w-3.5" />
                                </Link>
                            </p>
                        </section>
                    )}

                    <section className="mt-8">
                        <h2 className="text-base font-semibold tracking-tight text-graphite-900">Pemakaian terakhir</h2>

                        {recent.length === 0 ? (
                            <p className="mt-3 text-sm text-graphite-500">Belum ada dokumen PTW yang terpakai.</p>
                        ) : (
                            <div className="mt-3 overflow-hidden rounded-xl border border-graphite-200 bg-white">
                                <table className="w-full text-sm">
                                    <thead className="bg-graphite-50 text-left text-xs uppercase tracking-wide text-graphite-500">
                                        <tr>
                                            <th className="px-4 py-2.5 font-semibold">PTW</th>
                                            <th className="px-4 py-2.5 font-semibold">Pool</th>
                                            <th className="px-4 py-2.5 font-semibold">By</th>
                                            <th className="px-4 py-2.5 font-semibold">Date</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-graphite-100">
                                        {recent.map((row) => (
                                            <tr key={row.id}>
                                                <td className="px-4 py-2.5 font-medium text-graphite-900">{row.ptw_number}</td>
                                                <td className="px-4 py-2.5 text-graphite-600">
                                                    {row.kind === 'included' ? 'Kuota bulanan' : 'Kuota dibeli'}
                                                </td>
                                                <td className="px-4 py-2.5 text-graphite-600">{row.by ?? '-'}</td>
                                                <td className="px-4 py-2.5 text-graphite-500">
                                                    {row.at ? new Date(row.at).toLocaleDateString('id-ID', {
                                                        day: 'numeric', month: 'short', year: 'numeric',
                                                    }) : '-'}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>
                </>
            )}
        </AuthenticatedLayout>
    );
}

function Pool({ label, value, of, hint, tone = 'default' }) {
    return (
        <div
            className={cn(
                'rounded-xl border bg-white p-5 shadow-card',
                tone === 'spent' ? 'border-danger/30' : 'border-graphite-200'
            )}
        >
            <p className="font-mono text-[11px] uppercase tracking-[0.16em] text-graphite-400">{label}</p>
            <p
                className={cn(
                    'mt-2 text-3xl font-semibold tracking-tight',
                    tone === 'spent' ? 'text-danger' : tone === 'strong' ? 'text-navy-900' : 'text-graphite-900'
                )}
            >
                {value}
                {of != null && <span className="ml-1 text-base font-medium text-graphite-400">/ {of}</span>}
            </p>
            <p className="mt-2 text-xs leading-relaxed text-graphite-500">{hint}</p>
        </div>
    );
}
