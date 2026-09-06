import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, Clock, CreditCard, Mail, ShieldCheck, XCircle, ArrowRight } from 'lucide-react';
import PublicLayout from '@/Layouts/PublicLayout';
import PublicPageHero from '@/Components/shared/PublicPageHero';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';

/**
 * v2.51.0 -- where a registration stands.
 *
 * This is also the URL the payment provider redirects the customer back
 * to after checkout, which is exactly why it is READ ONLY. It renders
 * server state and nothing else: reaching it, refreshing it, or sharing
 * the link cannot mark anything paid or activate a workspace. That only
 * happens when the provider's signed notification reaches our webhook.
 *
 * So the page is honest about the in-between moment a customer will
 * genuinely experience -- "we're waiting for your payment to be
 * confirmed" -- rather than congratulating them on an activation that has
 * not happened yet.
 */
const FLOW = [
    { key: 'verify', label: 'Konfirmasi email', icon: Mail },
    { key: 'pay', label: 'Pembayaran', icon: CreditCard },
    { key: 'provision', label: 'Workspace siap', icon: ShieldCheck },
];

export default function RegistrationStatus({ registration, paymentConfigured, contactEmail }) {
    const { flash = {}, errors = {} } = usePage().props;
    const { post, processing } = useForm({});

    const isProvisioned = registration.status === 'provisioned';
    const isPaid = registration.status === 'paid' || isProvisioned;
    const isVerified = registration.is_verified;

    const stage = isProvisioned ? 3 : isPaid ? 2 : isVerified ? 1 : 0;

    const pay = (e) => {
        e.preventDefault();
        post(route('register.checkout', registration.token));
    };

    const resend = (e) => {
        e.preventDefault();
        post(route('register.resend', registration.token), { preserveScroll: true });
    };

    return (
        <PublicLayout>
            <Head title={`Registration ${registration.reference}`} />

            <PublicPageHero
                eyebrow={registration.reference}
                title={
                    isProvisioned
                        ? 'Workspace IOMS Anda sudah siap.'
                        : isPaid
                            ? 'Pembayaran diterima — penyiapan sedang berjalan.'
                            : isVerified
                                ? 'Selesaikan pembayaran untuk mengaktifkan IOMS.'
                                : 'Konfirmasi alamat email Anda untuk melanjutkan.'
                }
                subtitle={registration.company_name}
                size="sm"
            />

            <section className="bg-graphite-100 py-12 sm:py-16">
                <div className="mx-auto max-w-3xl space-y-6 px-4 sm:px-6">

                    {flash.success && <Notice tone="success">{flash.success}</Notice>}
                    {flash.info && <Notice tone="info">{flash.info}</Notice>}
                    {errors.payment && <Notice tone="danger">{errors.payment}</Notice>}
                    {registration.is_expired && !isProvisioned && (
                        <Notice tone="danger">
                            Pendaftaran ini sudah kedaluwarsa. Silakan mulai lagi dari halaman Mulai Berlangganan,
                            atau hubungi kami bila Anda sudah melakukan pembayaran.
                        </Notice>
                    )}

                    {/* Progress. Deliberately three plain steps rather than a
                        percentage bar -- a customer wants to know which gate
                        they are behind, not a number. */}
                    <div className="rounded-xl border border-steel-200/70 bg-white p-6 shadow-panel">
                        <ol className="grid gap-3 sm:grid-cols-3">
                            {FLOW.map((s, i) => {
                                const done = stage > i;
                                const current = stage === i;

                                return (
                                    <li
                                        key={s.key}
                                        className={cn(
                                            'flex items-center gap-3 rounded-lg border p-3',
                                            done
                                                ? 'border-success/20 bg-success/[0.06]'
                                                : current
                                                    ? 'border-brand-300 bg-gradient-to-b from-steel-100/70 to-white'
                                                    : 'border-steel-100 bg-white'
                                        )}
                                    >
                                        <span
                                            className={cn(
                                                'flex h-8 w-8 shrink-0 items-center justify-center rounded-[9px] text-white',
                                                done
                                                    ? 'bg-gradient-to-br from-success to-emerald-700'
                                                    : current
                                                        ? 'bg-gradient-to-br from-navy-800 to-brand-600'
                                                        : 'bg-steel-200 text-graphite-500'
                                            )}
                                        >
                                            {done ? <CheckCircle2 className="h-4 w-4" /> : <s.icon className="h-4 w-4" />}
                                        </span>
                                        <span className="min-w-0">
                                            <span className="block text-[13px] font-semibold text-navy-900">{s.label}</span>
                                            <span className="block text-[11px] text-graphite-500">
                                                {done ? 'Selesai' : current ? 'Sedang berjalan' : 'Menunggu'}
                                            </span>
                                        </span>
                                    </li>
                                );
                            })}
                        </ol>
                    </div>

                    {/* Summary */}
                    <div className="rounded-xl border border-steel-200/70 bg-white p-6 shadow-panel">
                        <h2 className="text-[15px] font-semibold tracking-tight text-navy-900">Data pendaftaran</h2>
                        <dl className="mt-4 grid gap-x-6 gap-y-3 sm:grid-cols-2">
                            <Row label="Nomor Referensi" value={registration.reference} />
                            <Row label="Perusahaan" value={registration.company_legal_name} />
                            <Row label="Administrator" value={registration.contact_email} />
                            <Row label="Paket" value={registration.plan_name} />
                            <Row label="Siklus" value={registration.billing_cycle === 'monthly' ? 'Bulanan' : 'Tahunan'} />
                            <Row label="Jumlah" value={registration.amount} strong />
                            {registration.invoice_number && (
                                <Row label="Invoice" value={`${registration.invoice_number} · ${registration.invoice_status}`} />
                            )}
                        </dl>
                    </div>

                    {/* The one action available at this stage. */}
                    {isProvisioned ? (
                        <div className="rounded-xl border border-success/20 bg-gradient-to-b from-success/[0.08] via-white to-white p-6 text-center shadow-panel">
                            <CheckCircle2 className="mx-auto h-8 w-8 text-success" />
                            <h2 className="mt-3 text-base font-semibold tracking-tight text-navy-900">
                                Workspace Anda sudah aktif
                            </h2>
                            <p className="mx-auto mt-2 max-w-md text-sm leading-relaxed text-graphite-600">
                                Masuk menggunakan {registration.contact_email} dan kata sandi yang Anda buat saat
                                mendaftar. IOMS tidak pernah mengirim kata sandi lewat email.
                            </p>
                            <Button className="mt-5" asChild>
                                <Link href={route('login')}>Masuk ke IOMS <ArrowRight className="h-4 w-4" /></Link>
                            </Button>
                        </div>
                    ) : isPaid ? (
                        <div className="rounded-xl border border-steel-200/70 bg-white p-6 text-center shadow-panel">
                            <Clock className="mx-auto h-8 w-8 text-brand-600" />
                            <h2 className="mt-3 text-base font-semibold tracking-tight text-navy-900">
                                Pembayaran dikonfirmasi — workspace sedang disiapkan
                            </h2>
                            <p className="mx-auto mt-2 max-w-md text-sm leading-relaxed text-graphite-600">
                                Proses ini biasanya selesai dalam hitungan detik. Anda akan menerima email begitu akun
                                administrator Anda siap.
                            </p>
                        </div>
                    ) : !isVerified ? (
                        <div className="rounded-xl border border-steel-200/70 bg-white p-6 text-center shadow-panel">
                            <Mail className="mx-auto h-8 w-8 text-brand-600" />
                            <h2 className="mt-3 text-base font-semibold tracking-tight text-navy-900">
                                Periksa kotak masuk Anda
                            </h2>
                            <p className="mx-auto mt-2 max-w-md text-sm leading-relaxed text-graphite-600">
                                Kami mengirim tautan konfirmasi ke <strong className="text-navy-800">{registration.contact_email}</strong>.
                                Konfirmasi untuk melanjutkan ke pembayaran.
                            </p>
                            <form onSubmit={resend}>
                                <Button type="submit" variant="outline" className="mt-5" disabled={processing}>
                                    Kirim ulang email konfirmasi
                                </Button>
                            </form>
                        </div>
                    ) : (
                        <div className="rounded-xl border border-steel-200/70 bg-gradient-to-b from-steel-100/70 via-white to-white p-6 shadow-panel">
                            <h2 className="text-[15px] font-semibold tracking-tight text-navy-900">Selesaikan pembayaran</h2>
                            <p className="mt-2 text-sm leading-relaxed text-graphite-600">
                                {paymentConfigured
                                    ? 'Anda akan diarahkan ke penyedia pembayaran kami. IOMS tidak pernah menerima atau menyimpan data kartu Anda, dan workspace hanya aktif setelah penyedia pembayaran mengonfirmasi pembayaran ke server kami.'
                                    : 'Pembayaran online belum diaktifkan pada instalasi ini. Melanjutkan akan menerbitkan invoice Anda dan tim kami akan menghubungi Anda dengan instruksi pembayaran — tidak ada penagihan di halaman ini.'}
                            </p>

                            <form onSubmit={pay}>
                                <Button type="submit" className="mt-5 w-full sm:w-auto" disabled={processing || registration.is_expired}>
                                    {processing
                                        ? 'Menyiapkan…'
                                        : paymentConfigured
                                            ? <>Bayar {registration.amount} <ArrowRight className="h-4 w-4" /></>
                                            : <>Terbitkan invoice saya <ArrowRight className="h-4 w-4" /></>}
                                </Button>
                            </form>
                        </div>
                    )}

                    {contactEmail && (
                        <p className="text-center text-xs text-graphite-500">
                            Ada pertanyaan tentang pendaftaran ini?{' '}
                            <a href={`mailto:${contactEmail}?subject=${encodeURIComponent(registration.reference)}`} className="font-medium text-brand-700 hover:underline">
                                Hubungi kami
                            </a>
                        </p>
                    )}
                </div>
            </section>
        </PublicLayout>
    );
}

function Row({ label, value, strong }) {
    return (
        <div>
            <dt className="text-[11px] uppercase tracking-wide text-graphite-400">{label}</dt>
            <dd className={cn('mt-0.5 text-sm', strong ? 'font-semibold text-navy-900' : 'text-graphite-700')}>
                {value || '—'}
            </dd>
        </div>
    );
}

function Notice({ tone, children }) {
    const Icon = tone === 'danger' ? XCircle : tone === 'success' ? CheckCircle2 : Clock;
    const tones = {
        success: 'border-success/20 bg-success/[0.07] text-emerald-900',
        info: 'border-brand-200 bg-brand-50 text-navy-800',
        danger: 'border-danger/20 bg-danger/[0.06] text-red-900',
    };

    return (
        <div className={cn('flex items-start gap-2.5 rounded-lg border p-4 text-sm leading-relaxed', tones[tone])}>
            <Icon className="mt-0.5 h-4 w-4 shrink-0" />
            <span>{children}</span>
        </div>
    );
}
