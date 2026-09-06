import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Printer, CheckCircle2, FileSignature } from 'lucide-react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import StatusBadge from '@/Components/shared/StatusBadge';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';

/**
 * v2.52.0 -- one BAST.
 *
 * The document's own structure, mirrored on screen: the two parties, what
 * was handed over, the acceptance statement, and the printable version.
 * "Terima" is the only state change offered here, and the acceptor is
 * derived server-side from the authenticated user — never chosen in the
 * form, because "who accepted this" is exactly the field a client must
 * not be able to set.
 */
export default function HandoverRecordShow({ record, canManage }) {
    const fmt = (v) => (v ? new Date(v).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' }) : '—');

    const accept = () => {
        if (! confirm('Tandai BAST ini sebagai diterima oleh Pihak Kedua?')) return;
        router.post(route('handover-records.accept', record.id), {}, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout>
            <Head title={record.bast_number} />

            <Link href={route('handover-records.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Kembali ke BAST
            </Link>

            <PageHeader
                icon={FileSignature}
                title={<>{record.bast_number}<StatusBadge value={record.status} /></>}
                subtitle={<>{record.type_label} · {fmt(record.handover_date)}</>}
            >
                <Button variant="outline" asChild>
                    <a href={route('handover-records.pdf', record.id)} target="_blank" rel="noreferrer">
                        <Printer className="h-4 w-4" /> Cetak BAST
                    </a>
                </Button>
                {canManage && record.status !== 'accepted' && (
                    <Button onClick={accept}><CheckCircle2 className="h-4 w-4" /> Tandai Diterima</Button>
                )}
            </PageHeader>

            <div className="grid gap-4 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader><CardTitle>{record.title}</CardTitle></CardHeader>
                    <CardContent className="space-y-5">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Party
                                label="Pihak Pertama"
                                name={record.first_party_name}
                                position={record.first_party_position}
                                org={record.first_party_organization || record.company_name}
                            />
                            <Party
                                label="Pihak Kedua"
                                name={record.second_party_name}
                                position={record.second_party_position}
                                org={record.second_party_organization}
                            />
                        </div>

                        {record.scope && (
                            <div>
                                <p className="text-[11px] uppercase tracking-wide text-graphite-400">Uraian / Ruang Lingkup</p>
                                <p className="mt-1 whitespace-pre-line text-sm leading-relaxed text-graphite-700">{record.scope}</p>
                            </div>
                        )}

                        <div className="rounded-lg border border-steel-100 bg-steel-50/60 p-4">
                            <p className="text-[11px] uppercase tracking-wide text-graphite-400">Pernyataan</p>
                            <p className="mt-1 whitespace-pre-line text-sm leading-relaxed text-graphite-700">{record.statement}</p>
                        </div>

                        {record.notes && (
                            <div>
                                <p className="text-[11px] uppercase tracking-wide text-graphite-400">Catatan</p>
                                <p className="mt-1 whitespace-pre-line text-sm leading-relaxed text-graphite-700">{record.notes}</p>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle>Rincian</CardTitle></CardHeader>
                    <CardContent>
                        <dl className="space-y-3">
                            <Row label="Nomor" value={record.bast_number} />
                            <Row label="Jenis" value={record.type_label} />
                            <Row label="Tanggal" value={fmt(record.handover_date)} />
                            <Row label="Referensi" value={record.reference_number} />
                            <Row label="Perusahaan" value={record.company_name} />
                            <Row label="Dibuat oleh" value={record.creator_name} />
                            <Row label="Diterima" value={record.accepted_at ? fmt(record.accepted_at) : null} />
                        </dl>
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}

function Party({ label, name, position, org }) {
    return (
        <div className="rounded-lg border border-steel-200/70 bg-gradient-to-b from-steel-100/60 via-white to-white p-4">
            <p className="text-[11px] font-semibold uppercase tracking-wide text-brand-700">{label}</p>
            <p className="mt-1.5 text-sm font-semibold text-navy-900">{name || '—'}</p>
            {position && <p className="text-xs text-graphite-500">{position}</p>}
            {org && <p className="text-xs text-graphite-500">{org}</p>}
        </div>
    );
}

function Row({ label, value }) {
    return (
        <div>
            <dt className="text-[11px] uppercase tracking-wide text-graphite-400">{label}</dt>
            <dd className="mt-0.5 text-sm text-graphite-700">{value || '—'}</dd>
        </div>
    );
}
