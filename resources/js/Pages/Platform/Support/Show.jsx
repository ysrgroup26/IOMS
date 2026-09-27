import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { ArrowLeft, Send, UserX, Building2, AlertTriangle } from 'lucide-react';

/*
 * v2.80.0 -- SATU PERCAKAPAN DUKUNGAN, BESERTA KONTEKSNYA.
 *
 * Bahasa Indonesia (ADR 040).
 *
 * The right-hand column is the reason this page exists rather than a mail
 * client: the commercial state of the organization asking. An operator
 * answering "why can I not save anything?" needs to see that the tenant is
 * read-only, beside the question. It is the same shared snapshot the
 * customer's own Billing page renders, so support cannot quote a state the
 * customer does not see.
 */

const STATUS_VARIANT = {
    open: 'destructive',
    in_progress: 'warning',
    waiting_customer: 'secondary',
    resolved: 'success',
    closed: 'secondary',
};

const LIFECYCLE = {
    active: { label: 'Aktif', variant: 'success' },
    grace: { label: 'Masa tenggang', variant: 'warning' },
    lapsed: { label: 'Read-only', variant: 'destructive' },
    suspended: { label: 'Ditangguhkan', variant: 'destructive' },
    cancelled: { label: 'Dibatalkan', variant: 'secondary' },
};

const INVOICE_LABEL = {
    draft: 'Draf', issued: 'Diterbitkan', paid: 'Lunas', overdue: 'Lewat jatuh tempo', void: 'Dibatalkan',
};

const waktu = (d) => (d ? new Date(d).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) : '—');

export default function SupportShow({
    ticket, messages = [], subscription, invoices = [],
    statuses = {}, priorities = {}, operators = [], tenant_options: tenantOptions = [], support_mailbox: mailbox,
}) {
    const { flash = {} } = usePage().props;
    const [associating, setAssociating] = useState('');

    const { data, setData, post, processing, errors, reset } = useForm({ body: '' });

    function reply(e) {
        e.preventDefault();
        post(route('platform.support.reply', ticket.id), { onSuccess: () => reset('body') });
    }

    function patch(payload) {
        router.put(route('platform.support.update', ticket.id), payload, { preserveScroll: true });
    }

    function associate(tenantId) {
        router.put(route('platform.support.associate', ticket.id), { tenant_id: tenantId || null }, { preserveScroll: true });
    }

    return (
        <PlatformLayout>
            <Head title={`${ticket.reference} -- ${ticket.subject}`} />

            <Link href={route('platform.support')} className="mb-4 inline-flex items-center gap-1.5 text-sm text-graphite-500 hover:text-graphite-700">
                <ArrowLeft className="h-4 w-4" /> Kembali ke antrean
            </Link>

            {flash.success && (
                <div className="mb-4 rounded-lg border border-success/20 bg-success/[0.07] p-4 text-sm text-emerald-900">{flash.success}</div>
            )}
            {flash.warning && (
                <div className="mb-4 rounded-lg border border-warning/25 bg-warning/[0.07] p-4 text-sm text-amber-900">{flash.warning}</div>
            )}

            <div className="mb-6">
                <div className="flex flex-wrap items-center gap-2">
                    <h1 className="text-[22px] font-semibold tracking-tight text-navy-900">{ticket.subject}</h1>
                    <Badge variant={STATUS_VARIANT[ticket.status] ?? 'secondary'}>{ticket.status_label}</Badge>
                </div>
                <p className="mt-1 text-sm text-graphite-500">
                    {ticket.reference} · {ticket.requester_name ? `${ticket.requester_name} · ` : ''}{ticket.requester_email}
                    {' · dibuat '}{waktu(ticket.created_at)}
                </p>
            </div>

            <div className="grid gap-4 lg:grid-cols-3">
                {/* ------------------------------ percakapan */}
                <div className="space-y-4 lg:col-span-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Percakapan</CardTitle>
                            <CardDescription>
                                Seluruh riwayat tiket ini. Pesan pelanggan mengembalikan tiket ke &quot;perlu
                                dijawab&quot;; balasan dukungan mengubahnya menjadi &quot;menunggu pelanggan&quot;.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {messages.length === 0 && <p className="text-sm text-graphite-400">Belum ada pesan.</p>}
                            {messages.map((m) => {
                                const inbound = m.direction === 'inbound';
                                return (
                                    <div
                                        key={m.id}
                                        className={`rounded-lg border p-3 ${inbound ? 'border-graphite-200 bg-graphite-50' : 'border-brand-100 bg-brand-50/40'}`}
                                    >
                                        <div className="mb-1.5 flex flex-wrap items-center justify-between gap-2">
                                            <span className="text-xs font-semibold text-graphite-700">
                                                {inbound ? (m.author_name || m.author_email || 'Pelanggan') : `${m.author_name} (IOMS)`}
                                            </span>
                                            <span className="text-[11px] text-graphite-400">
                                                {waktu(m.created_at)}
                                                {!inbound && !m.sent_at && (
                                                    <span className="ml-1.5 font-medium text-red-600">belum terkirim</span>
                                                )}
                                            </span>
                                        </div>
                                        <p className="whitespace-pre-wrap text-sm leading-relaxed text-graphite-700">{m.body}</p>
                                    </div>
                                );
                            })}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Balas pelanggan</CardTitle>
                            <CardDescription>Dikirim dari {mailbox}. Balasan pelanggan akan kembali ke tiket ini.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={reply} className="space-y-3">
                                <div className="space-y-1.5">
                                    <Label className="sr-only">Isi balasan</Label>
                                    <Textarea
                                        rows={6}
                                        value={data.body}
                                        onChange={(e) => setData('body', e.target.value)}
                                        placeholder="Tulis jawaban untuk pelanggan..."
                                    />
                                </div>
                                {errors.body && <p className="text-xs text-red-600">{errors.body}</p>}
                                <Button type="submit" disabled={processing || !data.body.trim()}>
                                    <Send className="h-4 w-4" /> Kirim balasan
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                </div>

                {/* ------------------------------ penanganan & konteks */}
                <div className="space-y-4">
                    <Card>
                        <CardHeader><CardTitle>Penanganan</CardTitle></CardHeader>
                        <CardContent className="space-y-3">
                            <div className="space-y-1.5">
                                <Label>Status</Label>
                                <Select value={ticket.status} onValueChange={(v) => patch({ status: v })}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        {Object.entries(statuses).map(([value, label]) => (
                                            <SelectItem key={value} value={value}>{label}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Prioritas</Label>
                                <Select value={ticket.priority} onValueChange={(v) => patch({ priority: v })}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        {Object.entries(priorities).map(([value, label]) => (
                                            <SelectItem key={value} value={value}>{label}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Penanggung jawab</Label>
                                <Select
                                    value={ticket.assigned_to ? String(ticket.assigned_to) : 'none'}
                                    onValueChange={(v) => patch({ assigned_to: v === 'none' ? null : v })}
                                >
                                    <SelectTrigger><SelectValue placeholder="Belum ditetapkan" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">Belum ditetapkan</SelectItem>
                                        {operators.map((o) => (
                                            <SelectItem key={o.id} value={String(o.id)}>{o.name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <p className="text-xs text-graphite-400">
                                Pesan terakhir pelanggan {waktu(ticket.last_customer_reply_at)} · balasan terakhir
                                {' '}{waktu(ticket.last_support_reply_at)}
                            </p>
                        </CardContent>
                    </Card>

                    {/* Pengirim yang belum dikenali: dikaitkan oleh manusia. */}
                    {!ticket.tenant && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2"><UserX className="h-4 w-4 text-amber-600" /> Pengirim belum dikenali</CardTitle>
                                <CardDescription>
                                    Alamat email ini tidak cocok dengan akun pengguna mana pun. Kaitkan secara manual,
                                    atau coba kenali ulang bila akunnya baru dibuat.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <Select value={associating} onValueChange={setAssociating}>
                                    <SelectTrigger><SelectValue placeholder="Pilih organisasi" /></SelectTrigger>
                                    <SelectContent>
                                        {tenantOptions.map((t) => (
                                            <SelectItem key={t.id} value={String(t.id)}>{t.name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <div className="flex flex-wrap gap-2">
                                    <Button size="sm" disabled={!associating} onClick={() => associate(associating)}>Kaitkan</Button>
                                    <Button size="sm" variant="outline" onClick={() => associate(null)}>Kenali ulang</Button>
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {/* Konteks komersial -- alasan halaman ini bukan klien email. */}
                    {ticket.tenant && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2"><Building2 className="h-4 w-4" /> {ticket.tenant.name}</CardTitle>
                                <CardDescription>Kondisi langganan pelanggan ini, sama dengan yang mereka lihat sendiri.</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                {subscription ? (
                                    <>
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="text-graphite-500">Posisi</span>
                                            <Badge variant={LIFECYCLE[subscription.lifecycle_state]?.variant ?? 'secondary'}>
                                                {LIFECYCLE[subscription.lifecycle_state]?.label ?? subscription.lifecycle_state}
                                            </Badge>
                                        </div>
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="text-graphite-500">Paket</span>
                                            <span className="font-medium text-graphite-800">{subscription.plan_name ?? '—'}</span>
                                        </div>
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="text-graphite-500">Berakhir</span>
                                            <span className="font-medium text-graphite-800">
                                                {subscription.is_lifetime ? 'Tanpa batas' : (subscription.period_ends_at ?? '—')}
                                            </span>
                                        </div>
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="text-graphite-500">Penagihan</span>
                                            <span className="font-medium text-graphite-800">{subscription.billing_mode_label}</span>
                                        </div>
                                        {!subscription.allows_writes && (
                                            <p className="flex items-start gap-2 rounded-md border border-warning/25 bg-warning/[0.07] p-2.5 text-xs leading-relaxed text-amber-900">
                                                <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                                Tenant ini sedang read-only. Bila pelanggan melaporkan tidak bisa menyimpan
                                                data, inilah sebabnya -- datanya utuh dan akan kembali setelah pembayaran
                                                terverifikasi.
                                            </p>
                                        )}
                                        <Link href={route('platform.tenants.show', ticket.tenant.id)} className="inline-block text-sm font-medium text-brand-600 hover:underline">
                                            Buka detail tenant &rarr;
                                        </Link>
                                    </>
                                ) : (
                                    <p className="text-graphite-400">Tenant ini belum memiliki catatan langganan.</p>
                                )}

                                {invoices.length > 0 && (
                                    <div className="border-t border-graphite-100 pt-3">
                                        <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-graphite-500">Tagihan terakhir</p>
                                        <div className="space-y-1.5">
                                            {invoices.map((inv) => (
                                                <div key={inv.id} className="flex items-center justify-between gap-2 text-xs">
                                                    <span className="text-graphite-600">{inv.invoice_number}</span>
                                                    <span className="text-graphite-500">
                                                        {inv.currency} {Number(inv.amount).toLocaleString('id-ID')} · {INVOICE_LABEL[inv.status] ?? inv.status}
                                                    </span>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>
        </PlatformLayout>
    );
}
