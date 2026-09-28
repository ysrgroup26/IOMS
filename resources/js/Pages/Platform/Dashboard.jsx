import { Head, Link } from '@inertiajs/react';
import { Building2, CheckCircle2, Clock, PauseCircle, Package, AlertTriangle, Lock, Wallet, Receipt, Bell } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';

const STAT_CARDS = [
    { key: 'tenants_total', label: 'Total tenant', icon: Building2 },
    { key: 'tenants_active', label: 'Aktif', icon: CheckCircle2 },
    { key: 'tenants_trial', label: 'Uji coba', icon: Clock },
    { key: 'tenants_suspended', label: 'Ditangguhkan', icon: PauseCircle },
    { key: 'packages_total', label: 'Paket', icon: Package },
    { key: 'subscriptions_active', label: 'Langganan aktif', icon: CheckCircle2 },
];

/* How the book is made up -- who actually pays (v2.80.0, ADR 041). */
const BILLING_MODE_LABELS = {
    paid: 'Berbayar (gateway)',
    manual: 'Manual (transfer)',
    complimentary: 'Gratis / internal',
};

/*
 * v2.79.0 -- THE OPERATIONS SECTION, IN BAHASA INDONESIA.
 *
 * Master Admin is an internal console operated by the IOMS team in
 * Indonesia, which is why this surface is written in Indonesian while the
 * customer-facing product keeps the language hierarchy in
 * docs/CONVENTIONS.md (English labels, Indonesian help text). The
 * distinction is deliberate and recorded in docs/ADR/040.
 *
 * WHAT IT SHOWS, and why these and not more cards: the states an operator
 * can act on. "Aktif" is context; "Menjelang jatuh tempo", "Masa tenggang"
 * and "Read-only" are work. The list underneath is that work, soonest
 * first, so the screen answers "who do I chase today" without a search.
 */
const LIFECYCLE_CARDS = [
    { key: 'expiring', label: 'Menjelang jatuh tempo', icon: Clock, tone: 'text-amber-600' },
    { key: 'grace', label: 'Masa tenggang', icon: AlertTriangle, tone: 'text-amber-600' },
    { key: 'lapsed', label: 'Read-only', icon: Lock, tone: 'text-red-600' },
    { key: 'active', label: 'Aktif', icon: CheckCircle2, tone: 'text-emerald-600' },
];

/* Status AKUN tenant -- pertanyaan yang berbeda dari posisi langganan. */
const ACCOUNT_LABEL = { active: 'Aktif', trial: 'Uji coba', suspended: 'Ditangguhkan' };

const STATE_LABEL = { grace: 'Masa tenggang', lapsed: 'Read-only', active: 'Aktif' };

const rupiah = (n) => 'Rp' + Math.round(Number(n || 0)).toLocaleString('id-ID');
const tanggal = (d) => (d ? new Date(d).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }) : '—');

export default function PlatformDashboard({ stats, recent_tenants: recentTenants, operations, platform_events: platformEvents = [], schema }) {
    const lifecycle = operations?.subscriptions?.counts ?? {};
    const attention = operations?.subscriptions?.attention ?? [];
    const payments = operations?.payments ?? {};
    const billingModes = operations?.subscriptions?.billing_modes ?? null;
    return (
        <PlatformLayout>
            <Head title="Ringkasan Platform" />

            <div className="mb-6">
                <h1 className="text-[22px] font-semibold tracking-tight text-navy-900">Ringkasan Platform</h1>
                <p className="mt-1 text-sm text-graphite-500">
                    Kondisi operasional seluruh platform: tenant, paket, langganan, dan pembayaran lintas tenant.
                </p>
            </div>

            {/* v2.84.1 -- APAKAH DATABASE SUDAH SESUAI DENGAN VERSI APLIKASI.

                Ini ada karena satu insiden nyata: Master Admin > Dukungan
                mengembalikan 500 di lingkungan terpasang sementara seluruh
                halaman konsol lain normal, karena tabel tiket belum pernah
                dibuat di sana. Di produksi pesan errornya memang tidak
                ditampilkan, jadi operator hanya melihat halaman gagal tanpa
                sebab.

                Panel ini MELAPORKAN, tidak menjalankan migrasi: menjalankan
                migrasi produksi saat halaman dibuka jauh lebih berbahaya
                daripada 500 yang digantikannya. */}
            {schema?.pending_count > 0 && (
                <div className="mb-6 rounded-lg border border-danger/25 bg-danger/[0.06] p-4 text-sm leading-relaxed text-red-900">
                    <p className="font-semibold">
                        {schema.pending_count} migrasi belum dijalankan pada server ini.
                    </p>
                    <p className="mt-1">
                        Halaman yang membutuhkan tabel baru akan gagal sampai migrasi dijalankan
                        (<code className="rounded bg-white/60 px-1">php artisan migrate --force</code>).
                        Migrasi tidak dijalankan otomatis dari halaman ini.
                    </p>
                    <ul className="mt-2 list-inside list-disc font-mono text-xs text-red-800">
                        {schema.pending.map((name) => <li key={name}>{name}</li>)}
                    </ul>
                </div>
            )}

            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                {STAT_CARDS.map(({ key, label, icon: Icon }) => (
                    <Card key={key}>
                        <CardContent className="flex flex-col gap-1 pt-6">
                            <Icon className="h-4 w-4 text-graphite-400" />
                            <span className="text-2xl font-bold text-graphite-900">{stats?.[key] ?? 0}</span>
                            <span className="text-xs text-graphite-500">{label}</span>
                        </CardContent>
                    </Card>
                ))}
            </div>

            {/* ---------------- Operasi langganan ---------------- */}
            <section className="mt-8">
                <div className="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                    <h2 className="text-base font-semibold tracking-tight text-navy-900">Operasi Langganan</h2>
                    <p className="text-xs text-graphite-500">
                        Status dihitung dari tanggal periode, bukan dari status tersimpan.
                        {typeof operations?.subscriptions?.grace_days === 'number'
                            ? ` Pengingat H-${operations.subscriptions.lead_days}, tenggang ${operations.subscriptions.grace_days} hari.`
                            : ''}
                    </p>
                </div>

                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    {LIFECYCLE_CARDS.map(({ key, label, icon: Icon, tone }) => (
                        <Card key={key}>
                            <CardContent className="flex flex-col gap-1 pt-6">
                                <Icon className={`h-4 w-4 ${tone}`} />
                                <span className="text-2xl font-bold text-graphite-900">{lifecycle[key] ?? 0}</span>
                                <span className="text-xs text-graphite-500">{label}</span>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <div className="mt-4 grid gap-4 lg:grid-cols-2">
                    {/* Pekerjaan hari ini: yang paling dekat jatuh tempo lebih dulu. */}
                    <Card>
                        <CardHeader><CardTitle>Perlu Perhatian</CardTitle></CardHeader>
                        <CardContent>
                            {attention.length ? (
                                <div className="divide-y divide-graphite-100">
                                    {attention.map((row) => (
                                        <div key={row.tenant_id} className="flex items-center justify-between gap-3 py-2.5">
                                            <div className="min-w-0">
                                                <Link href={route('platform.tenants.show', row.tenant_id)} className="truncate text-sm font-medium text-graphite-800 hover:underline">
                                                    {row.tenant}
                                                </Link>
                                                <p className="text-xs text-graphite-400">
                                                    {row.state === 'lapsed'
                                                        ? `Read-only sejak ${tanggal(row.grace_ends_at)}`
                                                        : row.state === 'grace'
                                                            ? `Tenggang sampai ${tanggal(row.grace_ends_at)}`
                                                            : `Berakhir ${tanggal(row.period_ends_at)}`}
                                                </p>
                                            </div>
                                            <Badge variant={row.state === 'lapsed' ? 'destructive' : row.state === 'grace' ? 'warning' : 'secondary'}>
                                                {row.state === 'active' && typeof row.days_left === 'number'
                                                    ? `${row.days_left} hari`
                                                    : STATE_LABEL[row.state] ?? row.state}
                                            </Badge>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-sm text-graphite-400">
                                    Tidak ada langganan yang mendekati jatuh tempo, dalam masa tenggang, atau read-only.
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    {/* Uang masuk dan yang masih tertagih, lintas penyedia pembayaran. */}
                    <Card>
                        <CardHeader><CardTitle>Pembayaran ({payments.window_days ?? 30} hari terakhir)</CardTitle></CardHeader>
                        <CardContent className="space-y-3">
                            <div className="flex items-start gap-3">
                                <Wallet className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-medium text-graphite-800">{rupiah(payments.paid_amount)} diterima</p>
                                    <p className="text-xs text-graphite-400">{payments.paid_count ?? 0} tagihan lunas</p>
                                </div>
                            </div>
                            <div className="flex items-start gap-3">
                                <Receipt className="mt-0.5 h-4 w-4 shrink-0 text-graphite-400" />
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-medium text-graphite-800">{rupiah(payments.outstanding_amount)} belum dibayar</p>
                                    <p className="text-xs text-graphite-400">
                                        {payments.outstanding_count ?? 0} tagihan terbuka
                                        {payments.overdue_count ? ` · ${payments.overdue_count} lewat jatuh tempo` : ''}
                                    </p>
                                </div>
                            </div>
                            {payments.failed_attempts > 0 && (
                                <div className="flex items-start gap-3">
                                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-red-600" />
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-medium text-graphite-800">{payments.failed_attempts} percobaan pembayaran gagal</p>
                                        <p className="text-xs text-graphite-400">Tidak ada akses yang berubah karenanya.</p>
                                    </div>
                                </div>
                            )}
                            {Object.keys(payments.by_provider ?? {}).length > 0 && (
                                <p className="border-t border-graphite-100 pt-3 text-xs text-graphite-500">
                                    Penyedia:{' '}
                                    {Object.entries(payments.by_provider).map(([gateway, n]) => `${gateway} (${n})`).join(' · ')}
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </section>

            {/* ---------------- Notifikasi platform ---------------- */}
            <Card className="mt-6">
                <CardHeader><CardTitle>Notifikasi Platform</CardTitle></CardHeader>
                <CardContent>
                    {platformEvents.length ? (
                        <div className="divide-y divide-graphite-100">
                            {platformEvents.map((event) => (
                                <div key={event.id} className="flex items-start gap-3 py-2.5">
                                    <Bell className={`mt-0.5 h-3.5 w-3.5 shrink-0 ${event.category === 'warning' ? 'text-red-600' : 'text-graphite-400'}`} />
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-medium text-graphite-800">{event.title}</p>
                                        {event.body && <p className="text-xs leading-relaxed text-graphite-500">{event.body}</p>}
                                        <p className="mt-0.5 text-[11px] text-graphite-400">{tanggal(event.created_at)}</p>
                                    </div>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <p className="text-sm text-graphite-400">
                            Belum ada peristiwa platform. Langganan baru, perpanjangan, perubahan paket, masa tenggang,
                            status read-only, dan pembayaran gagal akan muncul di sini.
                        </p>
                    )}
                </CardContent>
            </Card>

            {/* Siapa yang benar-benar membayar. Langganan gratis dan langganan
                berbayar yang sehat sama-sama "aktif" di atas. */}
            {billingModes && (
                <Card className="mt-6">
                    <CardHeader><CardTitle>Komposisi Penagihan</CardTitle></CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            {Object.entries(BILLING_MODE_LABELS).map(([mode, label]) => (
                                <div key={mode} className="rounded-md border border-graphite-100 px-3 py-2.5">
                                    <p className="text-xl font-bold text-graphite-900">{billingModes[mode] ?? 0}</p>
                                    <p className="text-xs text-graphite-500">{label}</p>
                                </div>
                            ))}
                        </div>
                        <p className="mt-3 text-xs text-graphite-400">
                            Langganan gratis tidak ditagih dan tidak masuk daftar Perlu Perhatian. Mode penagihan tidak
                            memengaruhi hak akses -- akses tetap mengikuti periode langganan.
                        </p>
                    </CardContent>
                </Card>
            )}

            <Card className="mt-6">
                <CardHeader>
                    <CardTitle>Tenant Terbaru</CardTitle>
                </CardHeader>
                <CardContent>
                    {recentTenants?.length ? (
                        <div className="divide-y divide-graphite-100">
                            {recentTenants.map((tenant) => (
                                <div key={tenant.id} className="flex items-center justify-between py-3">
                                    <div>
                                        <p className="text-sm font-medium text-graphite-800">{tenant.name}</p>
                                        <p className="text-xs text-graphite-400">
                                            {tenant.companies_count} perusahaan &middot; {tenant.users_count} pengguna
                                        </p>
                                    </div>
                                    <Badge variant={tenant.status === 'active' ? 'success' : 'secondary'}>{ACCOUNT_LABEL[tenant.status] ?? tenant.status}</Badge>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <p className="text-sm text-graphite-400">Belum ada tenant.</p>
                    )}
                    <Link href={route('platform.tenants')} className="mt-4 inline-block text-sm font-medium text-brand-600 hover:underline">
                        Lihat semua tenant &rarr;
                    </Link>
                </CardContent>
            </Card>
        </PlatformLayout>
    );
}
