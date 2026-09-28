import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import EmptyState from '@/Components/shared/EmptyState';
import { ArrowLeft, Pencil, Settings2, FileText, Plus, CheckCircle2 } from 'lucide-react';

/*
 * MASTER ADMIN -- TENANT DETAIL, IN BAHASA INDONESIA (v2.80.0, ADR 040).
 *
 * Everything commercial about one customer, and the two questions this page
 * has always had to keep apart:
 *
 *   Status  = what somebody DECIDED (active / trial / suspended).
 *   Standing = where the DATES put them, derived on every read.
 *
 * v2.80.0 adds the third: how they are PAID FOR (paid / manual /
 * complimentary), which is a separate question again -- it changes what is
 * invoiced, never what is granted (ADR 041).
 *
 * Every lifecycle figure below comes from the shared snapshot the customer's
 * own Billing page renders, so an operator and a customer cannot read two
 * different answers about one subscription.
 */

const STATUS_VARIANT = {
    active: 'success',
    trial: 'default',
    grace: 'warning',
    lapsed: 'destructive',
    suspended: 'destructive',
    cancelled: 'secondary',
};

const ACCOUNT_LABEL = { active: 'Aktif', trial: 'Uji coba', suspended: 'Ditangguhkan' };

const SUB_STATUS_LABEL = {
    trial: 'Uji coba',
    active: 'Aktif',
    suspended: 'Ditangguhkan',
    cancelled: 'Dibatalkan',
};

const LIFECYCLE_LABEL = {
    active: 'Aktif',
    grace: 'Masa tenggang',
    lapsed: 'Read-only',
    suspended: 'Ditangguhkan',
    cancelled: 'Dibatalkan',
};

const TYPE_LABEL = { trial: 'Uji coba', subscription: 'Langganan', lifetime: 'Seumur pakai' };

const CYCLE_LABEL = { monthly: 'Bulanan', yearly: 'Tahunan' };

const INVOICE_STATUS_VARIANT = {
    draft: 'secondary',
    issued: 'default',
    paid: 'success',
    overdue: 'destructive',
    void: 'secondary',
};

const INVOICE_STATUS_LABEL = {
    draft: 'Draf',
    issued: 'Diterbitkan',
    paid: 'Lunas',
    overdue: 'Lewat jatuh tempo',
    void: 'Dibatalkan',
};

const PURPOSE_LABEL = {
    onboarding: 'Aktivasi awal',
    renewal: 'Perpanjangan',
    plan_change: 'Perubahan paket',
};

const TX_STATUS_LABEL = {
    paid: 'berhasil',
    pending: 'menunggu',
    failed: 'gagal',
    expired: 'kedaluwarsa',
    refunded: 'dikembalikan',
};

function formatDate(value, withTime = true) {
    if (!value) return '—';
    return new Date(value).toLocaleString('id-ID', withTime ? { dateStyle: 'medium', timeStyle: 'short' } : { dateStyle: 'medium' });
}

export default function PlatformTenantDetail({ tenant, subscription, administrator, packages, subscriptionTypes, subscriptionStatuses, billingModes, invoices }) {
    const [subOpen, setSubOpen] = useState(false);
    const [invoiceOpen, setInvoiceOpen] = useState(false);

    return (
        <PlatformLayout>
            <Head title={tenant.name} />

            <Link href={route('platform.tenants')} className="mb-4 inline-flex items-center gap-1.5 text-sm text-graphite-500 hover:text-graphite-700">
                <ArrowLeft className="h-4 w-4" /> Kembali ke daftar tenant
            </Link>

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div className="flex items-center gap-2">
                        <h1 className="text-[22px] font-semibold tracking-tight text-navy-900">{tenant.name}</h1>
                        <Badge variant={STATUS_VARIANT[tenant.status] ?? 'secondary'}>{ACCOUNT_LABEL[tenant.status] ?? tenant.status}</Badge>
                        {/* The subscription standing belongs in the page title area
                            too: it is the first thing support needs and it is NOT
                            the same as the account badge beside it. */}
                        {subscription && (
                            <Badge variant={STATUS_VARIANT[subscription.lifecycle_state] ?? 'secondary'}>
                                {LIFECYCLE_LABEL[subscription.lifecycle_state] ?? subscription.lifecycle_state}
                            </Badge>
                        )}
                    </div>
                    <p className="mt-1 text-sm text-graphite-500">/{tenant.slug} -- Tenant #{tenant.id}</p>
                </div>
                <div className="flex gap-2">
                    <Button variant="outline" size="sm" asChild>
                        <Link href={route('platform.tenants')}>
                            <Pencil className="h-3.5 w-3.5" /> Ubah tenant
                        </Link>
                    </Button>
                    <Button variant="outline" size="sm" asChild>
                        <Link href={route('platform.tenants.grants', tenant.id)}>
                            <Settings2 className="h-3.5 w-3.5" /> Hak akses
                        </Link>
                    </Button>
                </div>
            </div>

            <div className="grid gap-4 md:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Informasi Tenant</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        <Row label="Nama" value={tenant.name} />
                        <Row label="Slug" value={tenant.slug} />
                        <Row label="Status akun" value={<Badge variant={STATUS_VARIANT[tenant.status] ?? 'secondary'}>{ACCOUNT_LABEL[tenant.status] ?? tenant.status}</Badge>} />
                        <Row label="Perusahaan" value={tenant.companies_count} />
                        <Row label="Pengguna" value={tenant.users_count} />
                        <Row label="Dibuat" value={formatDate(tenant.created_at)} />
                        <Row label="Diperbarui" value={formatDate(tenant.updated_at)} />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0">
                        <div>
                            <CardTitle>Langganan / Lisensi</CardTitle>
                            <CardDescription>Catatan komersial -- paket, jenis lisensi, status, dan cara penagihan.</CardDescription>
                        </div>
                        <Button variant="outline" size="sm" onClick={() => setSubOpen(true)}><Pencil className="h-3.5 w-3.5" /> Ubah</Button>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        {subscription ? (
                            <>
                                <Row label="Paket" value={subscription.plan_name ?? subscription.package_name ?? '—'} />
                                <Row label="Jenis lisensi" value={TYPE_LABEL[subscription.type] ?? subscription.type ?? 'Langganan'} />
                                {/* Status = keputusan. Standing = tanggal. Dipisah,
                                    karena menyatukannya adalah cara support akhirnya
                                    menangguhkan pelanggan yang sebenarnya hanya belum
                                    membayar. */}
                                <Row label="Status tersimpan" value={<Badge variant={STATUS_VARIANT[subscription.status] ?? 'secondary'}>{SUB_STATUS_LABEL[subscription.status] ?? subscription.status}</Badge>} />
                                <Row
                                    label="Posisi sebenarnya"
                                    value={
                                        <span className="inline-flex flex-wrap items-center justify-end gap-2">
                                            <Badge variant={STATUS_VARIANT[subscription.lifecycle_state] ?? 'secondary'}>
                                                {LIFECYCLE_LABEL[subscription.lifecycle_state] ?? subscription.lifecycle_state}
                                            </Badge>
                                            {subscription.lifecycle_state === 'grace' && subscription.grace_ends_at && (
                                                <span className="text-xs text-graphite-500">read-only mulai {formatDate(subscription.grace_ends_at, false)}</span>
                                            )}
                                            {subscription.lifecycle_state === 'lapsed' && (
                                                <span className="text-xs text-graphite-500">hanya baca, data utuh</span>
                                            )}
                                            {typeof subscription.days_remaining === 'number' && subscription.days_remaining >= 0 && (
                                                <span className="text-xs text-graphite-500">sisa {subscription.days_remaining} hari</span>
                                            )}
                                        </span>
                                    }
                                />
                                <Row
                                    label="Boleh menulis data"
                                    value={subscription.allows_writes
                                        ? <Badge variant="success">Ya</Badge>
                                        : <Badge variant="destructive">Tidak (read-only)</Badge>}
                                />
                                {/* v2.80.0 -- cara penagihan. Mengubah apa yang
                                    ditagih, bukan apa yang boleh dipakai. */}
                                <Row
                                    label="Cara penagihan"
                                    value={
                                        <span className="inline-flex items-center gap-2">
                                            <Badge variant={subscription.billing_mode === 'complimentary' ? 'secondary' : 'default'}>
                                                {subscription.billing_mode_label ?? subscription.billing_mode}
                                            </Badge>
                                            {subscription.billing_mode === 'complimentary' && (
                                                <span className="text-xs text-graphite-500">tidak ditagih</span>
                                            )}
                                        </span>
                                    }
                                />
                                {subscription.type !== 'lifetime' && <Row label="Siklus" value={CYCLE_LABEL[subscription.billing_cycle] ?? subscription.billing_cycle} />}
                                <Row label="Mulai" value={formatDate(subscription.starts_at, false)} />
                                {subscription.type === 'lifetime' ? (
                                    <Row label="Berakhir" value={<Badge variant="success">Seumur pakai -- tanpa batas waktu</Badge>} />
                                ) : (
                                    <Row label="Berakhir / perpanjangan" value={formatDate(subscription.period_ends_at ?? subscription.ends_at, false)} />
                                )}
                                {subscription.pending_plan_name && (
                                    <Row
                                        label="Perubahan terjadwal"
                                        value={
                                            <span className="text-xs text-graphite-600">
                                                {subscription.pending_plan_name}
                                                {subscription.pending_billing_cycle ? ` (${CYCLE_LABEL[subscription.pending_billing_cycle] ?? subscription.pending_billing_cycle})` : ''} pada akhir periode
                                            </span>
                                        }
                                    />
                                )}
                                {/* v2.82.0 -- the allowance, the purchase and the usage,
                                    kept apart. "Batas pengguna: 25" alone hid whether a
                                    tenant was at capacity or had bought their way past it. */}
                                <Row
                                    label="Pengguna aktif"
                                    value={subscription.included_users === null || subscription.included_users === undefined
                                        ? `${tenant.users_count} (tanpa batas)`
                                        : `${subscription.active_users ?? tenant.users_count} / ${subscription.seat_limit}`}
                                />
                                <Row
                                    label="Termasuk paket"
                                    value={subscription.included_users ?? 'Tanpa batas'}
                                />
                                <Row
                                    label="Pengguna tambahan"
                                    value={
                                        (subscription.additional_users ?? 0) > 0 ? (
                                            <span className="inline-flex flex-wrap items-center justify-end gap-2">
                                                <span>{subscription.additional_users}</span>
                                                <span className="text-xs text-graphite-500">
                                                    {new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 })
                                                        .format(subscription.additional_user_charge ?? 0)} / periode
                                                </span>
                                            </span>
                                        ) : 'Tidak ada'
                                    }
                                />
                                {subscription.license_key && <Row label="Kunci lisensi" value={<code className="text-xs">{subscription.license_key}</code>} />}
                            </>
                        ) : (
                            <p className="text-graphite-400">Tenant ini belum memiliki catatan langganan.</p>
                        )}
                    </CardContent>
                </Card>

                <Card className="md:col-span-2">
                    <CardHeader>
                        <CardTitle>Administrator</CardTitle>
                        <CardDescription>Akun Super Admin milik tenant ini -- dibuat bersamaan dengan tenantnya.</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        {administrator ? (
                            <>
                                <Row label="Nama" value={administrator.name} />
                                <Row label="Email" value={administrator.email} />
                                <Row label="Status akun" value={administrator.is_active ? <Badge variant="success">Aktif</Badge> : <Badge variant="secondary">Nonaktif</Badge>} />
                                <Row label="Dibuat" value={formatDate(administrator.created_at)} />
                            </>
                        ) : (
                            <p className="text-graphite-400">
                                Belum ada akun Administrator untuk tenant ini. Hal ini bisa terjadi pada tenant yang
                                dibuat sebelum langkah Administrator pertama ada.
                            </p>
                        )}
                    </CardContent>
                </Card>

                <Card className="md:col-span-2">
                    <CardHeader className="flex flex-row items-center justify-between space-y-0">
                        <div>
                            <CardTitle className="flex items-center gap-2"><FileText className="h-4 w-4" /> Tagihan &amp; Pembayaran</CardTitle>
                            {/* v2.80.0: the old description said no payment gateway
                                was connected. One is, and pretending otherwise made
                                this card look like a manual ledger when it is the
                                audit trail for verified payments. */}
                            <CardDescription>
                                Dokumen tagihan beserta setiap percobaan pembayaran yang tercatat. Pembayaran melalui
                                penyedia diselesaikan oleh webhook terverifikasi; tombol di sini untuk pelunasan manual
                                yang sudah dikonfirmasi di luar sistem.
                            </CardDescription>
                        </div>
                        <Button variant="outline" size="sm" onClick={() => setInvoiceOpen(true)}><Plus className="h-3.5 w-3.5" /> Terbitkan tagihan</Button>
                    </CardHeader>
                    <CardContent>
                        {invoices.length === 0 ? (
                            <EmptyState icon={FileText} title="Belum ada tagihan" />
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>No. tagihan</TableHead>
                                        <TableHead>Untuk</TableHead>
                                        <TableHead>Jumlah</TableHead>
                                        <TableHead>Jatuh tempo</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Dibayar</TableHead>
                                        <TableHead />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {invoices.map((inv) => <InvoiceRow key={inv.id} invoice={inv} />)}
                                </TableBody>
                            </Table>
                        )}
                        <Link href={route('platform.payments')} className="mt-4 inline-block text-sm font-medium text-brand-600 hover:underline">
                            Lihat seluruh riwayat pembayaran &rarr;
                        </Link>
                    </CardContent>
                </Card>
            </div>

            {subOpen && (
                <SubscriptionDialog
                    tenant={tenant}
                    subscription={subscription}
                    packages={packages}
                    types={subscriptionTypes}
                    statuses={subscriptionStatuses}
                    billingModes={billingModes}
                    onClose={() => setSubOpen(false)}
                />
            )}
            {invoiceOpen && <InvoiceDialog tenant={tenant} onClose={() => setInvoiceOpen(false)} />}
        </PlatformLayout>
    );
}

function Row({ label, value }) {
    return (
        <div className="flex items-center justify-between gap-4 border-b border-graphite-100 pb-2 last:border-0 last:pb-0 dark:border-slate-800">
            <span className="text-graphite-500">{label}</span>
            <span className="text-right font-medium text-graphite-800 dark:text-slate-100">{value}</span>
        </div>
    );
}

function InvoiceRow({ invoice }) {
    const { post, processing } = useForm({});

    function markPaid() {
        if (!confirm(`Tandai tagihan ${invoice.invoice_number} sebagai lunas?`)) return;
        post(route('platform.invoices.mark-paid', invoice.id), { method: 'put', preserveScroll: true });
    }

    const attempts = invoice.transactions ?? [];

    return (
        <TableRow>
            <TableCell className="font-medium">
                {invoice.invoice_number}
                {/* Setiap percobaan pembayaran, termasuk yang gagal -- inilah
                    bukti ketika pelanggan berkata sudah membayar. */}
                {attempts.length > 0 && (
                    <p className="mt-1 text-[11px] font-normal text-graphite-400">
                        {attempts.map((t) => `${t.gateway} ${TX_STATUS_LABEL[t.status] ?? t.status}`).join(' · ')}
                    </p>
                )}
            </TableCell>
            <TableCell className="text-xs text-graphite-500">
                {PURPOSE_LABEL[invoice.purpose] ?? '—'}
                {invoice.period_end ? <span className="block text-graphite-400">s.d. {formatDate(invoice.period_end, false)}</span> : null}
            </TableCell>
            <TableCell>{invoice.currency} {Number(invoice.amount).toLocaleString('id-ID')}</TableCell>
            <TableCell>{formatDate(invoice.due_date, false)}</TableCell>
            <TableCell><Badge variant={INVOICE_STATUS_VARIANT[invoice.status] ?? 'secondary'}>{INVOICE_STATUS_LABEL[invoice.status] ?? invoice.status}</Badge></TableCell>
            <TableCell>{formatDate(invoice.payment_date, false)}</TableCell>
            <TableCell>
                {invoice.status !== 'paid' && invoice.status !== 'void' && (
                    <Button variant="outline" size="sm" disabled={processing} onClick={markPaid}><CheckCircle2 className="h-3.5 w-3.5" /> Tandai lunas</Button>
                )}
            </TableCell>
        </TableRow>
    );
}

function SubscriptionDialog({ tenant, subscription, packages, types, statuses, billingModes, onClose }) {
    const { data, setData, put, processing, errors } = useForm({
        package_id: subscription?.package_id ? String(subscription.package_id) : (packages[0]?.id ? String(packages[0].id) : ''),
        type: subscription?.type || 'subscription',
        status: subscription?.status || 'active',
        billing_cycle: subscription?.billing_cycle || 'monthly',
        billing_mode: subscription?.billing_mode || 'paid',
        seat_limit: subscription?.seat_limit || '',
        additional_users: subscription?.additional_users ?? 0,
        license_key: subscription?.license_key || '',
        billing_reference: subscription?.billing_reference || '',
        starts_at: subscription?.starts_at ? subscription.starts_at.slice(0, 10) : '',
        ends_at: subscription?.ends_at ? subscription.ends_at.slice(0, 10) : '',
        notes: subscription?.notes || '',
    });

    function submit(e) {
        e.preventDefault();
        put(route('platform.tenants.subscription.update', tenant.id), { preserveScroll: true, onSuccess: onClose });
    }

    const modes = billingModes ?? { paid: 'Berbayar (gateway)', manual: 'Manual (transfer)', complimentary: 'Gratis / internal' };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-h-[85vh] overflow-y-auto">
                <DialogHeader><DialogTitle>Langganan / Lisensi -- {tenant.name}</DialogTitle></DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1.5">
                            <Label>Paket</Label>
                            <Select value={data.package_id} onValueChange={(v) => setData('package_id', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>{packages.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Jenis lisensi</Label>
                            <Select value={data.type} onValueChange={(v) => setData('type', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>{types.map((t) => <SelectItem key={t} value={t}>{TYPE_LABEL[t] ?? t}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Status</Label>
                            <Select value={data.status} onValueChange={(v) => setData('status', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>{statuses.map((s) => <SelectItem key={s} value={s}>{SUB_STATUS_LABEL[s] ?? s}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                        {data.type !== 'lifetime' && (
                            <div className="space-y-1.5">
                                <Label>Siklus penagihan</Label>
                                <Select value={data.billing_cycle} onValueChange={(v) => setData('billing_cycle', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent><SelectItem value="monthly">Bulanan</SelectItem><SelectItem value="yearly">Tahunan</SelectItem></SelectContent>
                                </Select>
                            </div>
                        )}
                        {/* v2.80.0 -- siapa yang mengambil uangnya. Tidak memberi
                            atau mencabut akses apa pun. */}
                        <div className="space-y-1.5 col-span-2">
                            <Label>Cara penagihan</Label>
                            <Select value={data.billing_mode} onValueChange={(v) => setData('billing_mode', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    {Object.entries(modes).map(([value, label]) => (
                                        <SelectItem key={value} value={value}>{label}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <p className="text-xs text-graphite-500">
                                Gratis / internal tidak akan ditagih dan tidak masuk daftar tunggakan. Hak akses tetap
                                mengikuti periode langganan, bukan cara penagihan.
                            </p>
                        </div>
                        <div className="space-y-1.5"><Label>Batas pengguna (kosong = ikut paket)</Label><Input type="number" min="1" value={data.seat_limit} onChange={(e) => setData('seat_limit', e.target.value)} /></div>
                        {/* v2.82.0 -- paid capacity beyond the plan. An operator sets
                            it for a negotiated arrangement; the customer sets it
                            themselves from Billing. Both write the same column. */}
                        <div className="space-y-1.5"><Label>Pengguna tambahan (berbayar)</Label><Input type="number" min="0" value={data.additional_users} onChange={(e) => setData('additional_users', e.target.value)} /></div>
                        <div className="space-y-1.5"><Label>Kunci lisensi</Label><Input value={data.license_key} onChange={(e) => setData('license_key', e.target.value)} /></div>
                        <div className="space-y-1.5"><Label>Mulai</Label><Input type="date" value={data.starts_at} onChange={(e) => setData('starts_at', e.target.value)} /></div>
                        {data.type !== 'lifetime' && (
                            <div className="space-y-1.5"><Label>Berakhir / perpanjangan</Label><Input type="date" value={data.ends_at} onChange={(e) => setData('ends_at', e.target.value)} /></div>
                        )}
                    </div>
                    <div className="space-y-1.5"><Label>Referensi penagihan</Label><Input value={data.billing_reference} onChange={(e) => setData('billing_reference', e.target.value)} /></div>
                    <div className="space-y-1.5"><Label>Catatan</Label><Textarea rows={3} value={data.notes} onChange={(e) => setData('notes', e.target.value)} /></div>
                    {Object.keys(errors).length > 0 && (
                        <div className="rounded-md border border-red-200 bg-red-50 p-2 text-xs text-red-700">{Object.values(errors).map((m, i) => <p key={i}>{m}</p>)}</div>
                    )}
                    <DialogFooter><Button type="button" variant="outline" onClick={onClose}>Batal</Button><Button type="submit" disabled={processing}>Simpan</Button></DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function InvoiceDialog({ tenant, onClose }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        period_start: '', period_end: '', amount: '', currency: 'IDR', due_date: '', notes: '',
        // v2.70.0: what settling this invoice BUYS. Default true, because
        // an invoice raised against a tenant is overwhelmingly a period of
        // service -- an adjustment or one-off charge is the exception, and
        // should be the thing an operator has to tick.
        extends_period: true,
    });

    function submit(e) {
        e.preventDefault();
        post(route('platform.tenants.invoices.store', tenant.id), { preserveScroll: true, onSuccess: () => { reset(); onClose(); } });
    }

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <DialogHeader><DialogTitle>Terbitkan tagihan -- {tenant.name}</DialogTitle></DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1.5"><Label>Awal periode</Label><Input type="date" value={data.period_start} onChange={(e) => setData('period_start', e.target.value)} /></div>
                        <div className="space-y-1.5"><Label>Akhir periode</Label><Input type="date" value={data.period_end} onChange={(e) => setData('period_end', e.target.value)} /></div>
                        <div className="space-y-1.5"><Label>Jumlah</Label><Input type="number" min="0" step="0.01" value={data.amount} onChange={(e) => setData('amount', e.target.value)} /></div>
                        <div className="space-y-1.5"><Label>Mata uang</Label><Input maxLength={3} value={data.currency} onChange={(e) => setData('currency', e.target.value.toUpperCase())} /></div>
                        <div className="space-y-1.5 col-span-2"><Label>Jatuh tempo</Label><Input type="date" value={data.due_date} onChange={(e) => setData('due_date', e.target.value)} /></div>
                    </div>
                    <div className="space-y-1.5"><Label>Catatan</Label><Textarea rows={2} value={data.notes} onChange={(e) => setData('notes', e.target.value)} /></div>
                    {/* Melunasi tagihan ini akan memperpanjang periode, sama
                        seperti pelunasan melalui penyedia pembayaran. Lepas
                        centang untuk biaya sekali bayar atau penyesuaian. */}
                    <label className="flex items-start gap-2 rounded-md border border-graphite-200 p-3 text-sm">
                        <input
                            type="checkbox"
                            className="mt-0.5"
                            checked={data.extends_period}
                            onChange={(e) => setData('extends_period', e.target.checked)}
                        />
                        <span>
                            <span className="font-medium">Memperpanjang periode langganan</span>
                            <span className="block text-xs text-graphite-500">
                                Menandai tagihan ini lunas akan memajukan tanggal perpanjangan satu siklus penagihan.
                                Lepas centang untuk biaya sekali bayar atau penyesuaian.
                            </span>
                        </span>
                    </label>
                    {Object.keys(errors).length > 0 && (
                        <div className="rounded-md border border-red-200 bg-red-50 p-2 text-xs text-red-700">{Object.values(errors).map((m, i) => <p key={i}>{m}</p>)}</div>
                    )}
                    <DialogFooter><Button type="button" variant="outline" onClick={onClose}>Batal</Button><Button type="submit" disabled={processing}>Terbitkan</Button></DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
