import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/shared/PageHeader';
import { Card, CardContent } from '@/Components/ui/card';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import EmptyState from '@/Components/shared/EmptyState';
import { LifeBuoy, Plus, UserX, Clock } from 'lucide-react';

/*
 * v2.80.0 -- MASTER ADMIN > DUKUNGAN. Sebuah daftar kerja, bukan kotak surat.
 *
 * Bahasa Indonesia, seperti seluruh konsol ini (ADR 040).
 *
 * The default tab is "Perlu dijawab", not "Semua": the question an operator
 * opens this page with is what is unanswered, and a queue whose default is
 * everything is a mailbox with extra steps. "Menunggu pelanggan" is
 * deliberately a separate tab rather than part of the default, because a
 * ticket waiting on the customer is not our work.
 */

const STATUS_VARIANT = {
    open: 'destructive',
    in_progress: 'warning',
    waiting_customer: 'secondary',
    resolved: 'success',
    closed: 'secondary',
};

const PRIORITY_VARIANT = {
    urgent: 'destructive',
    high: 'warning',
    normal: 'secondary',
    low: 'outline',
};

const TABS = [
    { key: 'needs_action', label: 'Perlu dijawab' },
    { key: 'waiting_customer', label: 'Menunggu pelanggan' },
    { key: 'unidentified', label: 'Pengirim belum dikenali' },
    { key: 'resolved', label: 'Selesai' },
    { key: 'all', label: 'Semua' },
];

/* Umur tiket dihitung dari pesan pelanggan terakhir, bukan dari tanggal dibuat. */
function usia(hours) {
    if (hours === null || hours === undefined) return '—';
    if (hours < 1) return 'baru saja';
    if (hours < 24) return `${hours} jam`;
    return `${Math.floor(hours / 24)} hari`;
}

export default function SupportIndex({ tickets = [], filter, counts = {}, priorities = {}, support_mailbox: mailbox }) {
    const { flash = {} } = usePage().props;
    const [open, setOpen] = useState(false);

    return (
        <PlatformLayout>
            <Head title="Dukungan" />

            <PageHeader
                icon={LifeBuoy}
                title="Dukungan"
                subtitle={`Pertanyaan pelanggan sebagai pekerjaan: siapa yang menunggu, sudah berapa lama, dan siapa yang menanganinya. Balasan dikirim dari ${mailbox}.`}
            >
                <Button size="sm" onClick={() => setOpen(true)}><Plus className="h-4 w-4" /> Catat pesan masuk</Button>
            </PageHeader>

            {flash.success && (
                <div className="mb-4 rounded-lg border border-success/20 bg-success/[0.07] p-4 text-sm text-emerald-900">{flash.success}</div>
            )}
            {flash.warning && (
                <div className="mb-4 rounded-lg border border-warning/25 bg-warning/[0.07] p-4 text-sm text-amber-900">{flash.warning}</div>
            )}

            {/* Ingestion email otomatis belum tersedia -- dinyatakan terbuka,
                bukan disembunyikan, supaya tidak ada yang menganggap kotak
                surat dukungan sudah masuk sendiri ke sini. */}
            <div className="mb-4 rounded-lg border border-graphite-200 bg-white p-4 text-sm leading-relaxed text-graphite-600">
                Email ke <strong>{mailbox}</strong> belum masuk ke antrean ini secara otomatis. Catat pesan yang
                diterima melalui tombol di atas; pencatatan manual dan ingestion otomatis nanti menggunakan jalur yang
                sama, sehingga aturan status tiket tidak akan berbeda.
            </div>

            <div className="mb-4 flex flex-wrap gap-2">
                {TABS.map((tab) => (
                    <button
                        key={tab.key}
                        onClick={() => router.get(route('platform.support'), tab.key === 'needs_action' ? {} : { filter: tab.key }, { preserveState: true, replace: true })}
                        className={`rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${
                            filter === tab.key ? 'bg-brand-50 text-brand-700' : 'text-graphite-600 hover:bg-graphite-100'
                        }`}
                    >
                        {tab.label}
                        {typeof counts[tab.key] === 'number' && (
                            <span className="ml-1.5 text-xs text-graphite-400">{counts[tab.key]}</span>
                        )}
                    </button>
                ))}
            </div>

            <Card>
                <CardContent className="p-0">
                    {tickets.length === 0 ? (
                        <div className="p-6">
                            <EmptyState
                                icon={LifeBuoy}
                                title="Tidak ada tiket di antrean ini"
                                description="Tiket yang menunggu jawaban akan muncul paling atas, yang paling lama menunggu lebih dulu."
                            />
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Tiket</TableHead>
                                        <TableHead>Pengirim</TableHead>
                                        <TableHead>Organisasi</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Prioritas</TableHead>
                                        <TableHead>Menunggu</TableHead>
                                        <TableHead>Penanggung jawab</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {tickets.map((t) => (
                                        <TableRow key={t.id}>
                                            <TableCell className="max-w-xs">
                                                <Link href={route('platform.support.show', t.id)} className="font-medium text-navy-900 hover:text-brand-600 hover:underline">
                                                    {t.subject}
                                                </Link>
                                                <span className="block text-[11px] text-graphite-400">
                                                    {t.reference} · {t.messages_count} pesan
                                                </span>
                                            </TableCell>
                                            <TableCell>
                                                {t.requester_name || '—'}
                                                <span className="block text-[11px] text-graphite-400">{t.requester_email}</span>
                                            </TableCell>
                                            <TableCell>
                                                {t.tenant ? (
                                                    <Link href={route('platform.tenants.show', t.tenant.id)} className="text-sm text-brand-600 hover:underline">
                                                        {t.tenant.name}
                                                    </Link>
                                                ) : (
                                                    <span className="inline-flex items-center gap-1 text-xs text-amber-700">
                                                        <UserX className="h-3 w-3" /> belum dikenali
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant={STATUS_VARIANT[t.status] ?? 'secondary'}>{t.status_label}</Badge>
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant={PRIORITY_VARIANT[t.priority] ?? 'secondary'}>
                                                    {priorities[t.priority] ?? t.priority}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap text-sm text-graphite-600">
                                                {t.age_hours !== null && t.age_hours !== undefined ? (
                                                    <span className="inline-flex items-center gap-1">
                                                        <Clock className={`h-3 w-3 ${t.age_hours >= 48 ? 'text-red-600' : t.age_hours >= 24 ? 'text-amber-600' : 'text-graphite-400'}`} />
                                                        {usia(t.age_hours)}
                                                    </span>
                                                ) : '—'}
                                            </TableCell>
                                            <TableCell className="text-sm text-graphite-600">{t.assignee ?? '—'}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </CardContent>
            </Card>

            {open && <LogDialog onClose={() => setOpen(false)} />}
        </PlatformLayout>
    );
}

function LogDialog({ onClose }) {
    const { data, setData, post, processing, errors } = useForm({
        requester_email: '', requester_name: '', subject: '', body: '', channel: 'email',
    });

    function submit(e) {
        e.preventDefault();
        post(route('platform.support.store'), { onSuccess: onClose });
    }

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <DialogHeader><DialogTitle>Catat pesan masuk</DialogTitle></DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <p className="text-xs leading-relaxed text-graphite-500">
                        Pesan akan dicatat sebagai pesan dari pelanggan. Bila alamat email cocok dengan akun pengguna
                        yang ada, tiket langsung terkait ke organisasinya; bila tidak, tiket masuk antrean
                        &quot;pengirim belum dikenali&quot;.
                    </p>
                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1.5">
                            <Label>Email pengirim</Label>
                            <Input type="email" value={data.requester_email} onChange={(e) => setData('requester_email', e.target.value)} />
                        </div>
                        <div className="space-y-1.5">
                            <Label>Nama pengirim</Label>
                            <Input value={data.requester_name} onChange={(e) => setData('requester_name', e.target.value)} />
                        </div>
                    </div>
                    <div className="space-y-1.5">
                        <Label>Subjek</Label>
                        <Input value={data.subject} onChange={(e) => setData('subject', e.target.value)} />
                    </div>
                    <div className="space-y-1.5">
                        <Label>Isi pesan</Label>
                        <Textarea rows={6} value={data.body} onChange={(e) => setData('body', e.target.value)} />
                    </div>
                    {Object.keys(errors).length > 0 && (
                        <div className="rounded-md border border-red-200 bg-red-50 p-2 text-xs text-red-700">
                            {Object.values(errors).map((m, i) => <p key={i}>{m}</p>)}
                        </div>
                    )}
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>Batal</Button>
                        <Button type="submit" disabled={processing}>Catat</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
