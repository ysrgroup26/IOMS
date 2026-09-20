import { Link } from '@inertiajs/react';
import { ArrowRight, Check, Mail, Building2, Clock } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import AuthLayout from '@/Layouts/AuthLayout';

/**
 * v2.74.0 -- THE FORK, AND WHY IT IS A PAGE.
 *
 * The account exists. The only question left is whether they want to set
 * a subscription up now, and this page asks it once, plainly, with both
 * answers given equal standing.
 *
 * It could have been a redirect. That is what the first cut did -- straight
 * to the Account area -- and it is worse in a way that is easy to miss:
 * an account that has just been created has no organization, no data and
 * no subscription, so the Account area it lands on is almost entirely
 * empty. Nothing is broken, but it reads as though something is, and the
 * new account has no idea what it is supposed to do next.
 *
 * The opposite redirect -- straight into plan selection -- is the mistake
 * this whole release exists to stop making.
 *
 * So: one screen, two real options. "Maybe later" is a proper button with
 * a real destination, not a greyed-out escape hatch, because a product
 * that lets you create an account without buying has to mean it. See
 * docs/ADR/038-account-organization-subscription.md.
 */
export default function AccountCreated({ account }) {
    return (
        <AuthLayout
            title="Account Created"
            heading="Your account is ready"
            subheading="Akun Anda sudah aktif. Silakan lanjutkan menyiapkan langganan, atau lakukan nanti."
            footer={
                <div className="mt-6 rounded-lg border border-steel-200/70 bg-steel-50/70 px-3.5 py-3 text-center">
                    <p className="text-xs leading-relaxed text-graphite-600">
                        Belum yakin paket mana yang sesuai?{' '}
                        <Link href={route('pricing')} className="font-semibold text-brand-700 hover:underline">Bandingkan paket</Link>
                        {' '}terlebih dahulu.
                    </p>
                </div>
            }
        >
            {/* Who they now are. Stated rather than celebrated -- the useful
                information is the address, because the confirmation email is
                already on its way to it. */}
            <div className="rounded-lg border border-success/30 bg-success/5 p-3.5">
                <div className="flex items-start gap-2.5">
                    <span className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-success text-white">
                        <Check className="h-3 w-3" aria-hidden="true" />
                    </span>
                    <div className="min-w-0">
                        <p className="text-[13px] font-semibold text-navy-900">{account.name}</p>
                        <p className="mt-0.5 truncate text-xs text-graphite-600">{account.email}</p>
                    </div>
                </div>

                {!account.email_verified && (
                    <p className="mt-2.5 flex items-start gap-2 border-t border-success/20 pt-2.5 text-xs leading-relaxed text-graphite-600">
                        <Mail className="mt-0.5 h-3.5 w-3.5 shrink-0 text-graphite-400" aria-hidden="true" />
                        Kami telah mengirim tautan konfirmasi ke alamat tersebut. Konfirmasi diperlukan
                        sebelum Anda dapat berlangganan.
                    </p>
                )}
            </div>

            {/* The two answers. The primary is first and styled as the
                expected path, but the alternative is a real button with a
                real destination -- not a link buried in small print. */}
            <div className="mt-5 space-y-3">
                <ForkOption
                    icon={Building2}
                    title="Continue setup"
                    body="Lengkapi data perusahaan, pilih paket, lalu lanjutkan ke pembayaran."
                    action={
                        <Button asChild className="group w-full">
                            <Link href={route('subscribe.setup')}>
                                Continue setup
                                <ArrowRight className="h-4 w-4 transition-transform duration-200 motion-safe:group-hover:translate-x-0.5" />
                            </Link>
                        </Button>
                    }
                />

                <ForkOption
                    icon={Clock}
                    title="Maybe later"
                    body="Masuk ke area akun Anda. Tidak ada organisasi, langganan, atau tagihan yang dibuat."
                    action={
                        <Button variant="outline" asChild className="w-full">
                            <Link href={route('account.overview')}>Maybe later</Link>
                        </Button>
                    }
                />
            </div>
        </AuthLayout>
    );
}

function ForkOption({ icon: Icon, title, body, action }) {
    return (
        <div className="rounded-lg border border-steel-200 bg-white p-3.5">
            <div className="flex items-start gap-2.5">
                <Icon className="mt-0.5 h-4 w-4 shrink-0 text-brand-600" aria-hidden="true" />
                <div className="min-w-0 flex-1">
                    <p className="text-[13px] font-semibold text-navy-900">{title}</p>
                    <p className="mt-0.5 text-xs leading-relaxed text-graphite-600">{body}</p>
                </div>
            </div>
            <div className="mt-3">{action}</div>
        </div>
    );
}
