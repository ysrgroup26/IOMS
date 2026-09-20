import { Head, Link, useForm, usePage, router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import BrandWordmark from '@/Components/shared/BrandWordmark';
import { FieldGrid, Field } from '@/Components/shared/DetailFields';
import {
    ArrowRight, BadgeCheck, MailWarning, ShieldCheck, LogOut, Building2,
    Loader2, CheckCircle2, ExternalLink,
} from 'lucide-react';

/**
 * v2.74.0 -- THE ACCOUNT AREA.
 *
 * Where somebody lands when they have an IOMS identity but not yet an
 * organization. The hardest thing about this page is tone: an
 * unsubscribed account has done nothing wrong, so nothing here may read
 * as an error, a lockout or a nag.
 *
 * WHAT THAT MEANS CONCRETELY:
 *
 *   - "No active subscription" is stated as a FACT in neutral steel, not
 *     as a warning in amber. It is a state, not a problem.
 *   - There is no interstitial, no countdown, and no forced redirect to
 *     pricing. The plan cards are here, visible, one click away, and
 *     ignorable.
 *   - Unverified email IS surfaced with weight, because that one does
 *     block something real (subscribing) and has an action that fixes it.
 *
 * It renders NO tenant data, structurally: a user with no tenant has
 * nothing for TenantScope to resolve. Everything here is the account's
 * own row or the public plan catalogue.
 *
 * DELIBERATELY NOT INSIDE AuthenticatedLayout. That layout is the
 * operational shell -- workspace switcher, department rail, Work Center --
 * and every one of those is meaningless without a tenant. Rendering it
 * around an empty product would be the "looks like an error" failure this
 * page exists to avoid.
 */
export default function AccountOverview({ account, hasOrganization, organization, pendingOrder, plans, googleEnabled }) {
    const { version } = usePage().props;

    return (
        <div className="min-h-screen bg-steel-50/60">
            <Head title="Account" />

            <AccountTopBar hasOrganization={hasOrganization} />

            <main className="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6 lg:py-12">
                <header className="mb-6">
                    <h1 className="text-[24px] font-semibold tracking-tight text-navy-900">
                        Hi {account.name.split(' ')[0]}
                    </h1>
                    <p className="mt-1 text-sm text-graphite-500">
                        Ini akun IOMS Anda. Kelola identitas dan keamanan di sini, dan pilih paket
                        kapan pun Anda siap.
                    </p>
                </header>

                {!account.email_verified && <VerifyEmailPanel email={account.email} />}

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <div className="space-y-4 lg:col-span-2">
                        {hasOrganization
                            ? <OrganizationPanel organization={organization} />
                            : <NoSubscriptionPanel plans={plans} pendingOrder={pendingOrder} canSubscribe={account.email_verified} />}
                    </div>

                    <div className="space-y-4">
                        <ProfileCard account={account} />
                        <SecurityCard account={account} googleEnabled={googleEnabled} />
                    </div>
                </div>

                <p className="mt-8 text-center text-xs text-graphite-400">
                    {version?.edition} &middot; v{version?.number}
                </p>
            </main>
        </div>
    );
}

/**
 * A minimal bar, not the operational shell. It carries the brand, a way
 * back into the product for somebody who DOES have one, and sign-out.
 */
function AccountTopBar({ hasOrganization }) {
    return (
        <div className="border-b border-steel-200/70 bg-navy-900">
            <div className="mx-auto flex w-full max-w-5xl items-center justify-between gap-3 px-4 py-3 sm:px-6">
                <BrandWordmark className="h-7 w-auto" tone="dark" />

                <div className="flex items-center gap-2">
                    {hasOrganization && (
                        <Button variant="ghost" size="sm" asChild className="text-navy-300 hover:bg-white/[0.08] hover:text-white">
                            <Link href={route('dashboard')}>Open IOMS <ArrowRight className="h-4 w-4" /></Link>
                        </Button>
                    )}
                    <Button
                        variant="ghost"
                        size="sm"
                        className="text-navy-300 hover:bg-white/[0.08] hover:text-white"
                        onClick={() => router.post(route('logout'))}
                    >
                        <LogOut className="h-4 w-4" /> Sign out
                    </Button>
                </div>
            </div>
        </div>
    );
}

/**
 * The one thing on this page that IS a call to action, because it blocks
 * something real and has a one-click remedy.
 */
function VerifyEmailPanel({ email }) {
    const { post, processing } = useForm({});
    const [sent, setSent] = useState(false);

    return (
        <div className="mb-6 flex flex-col gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-amber-900 dark:bg-amber-950/30">
            <div className="flex items-start gap-3">
                <MailWarning className="mt-0.5 h-5 w-5 shrink-0 text-amber-600" aria-hidden="true" />
                <div className="min-w-0">
                    <p className="text-sm font-semibold text-amber-900">Confirm your email address</p>
                    <p className="mt-0.5 text-xs leading-relaxed text-amber-800">
                        Kami mengirim tautan konfirmasi ke <strong>{email}</strong>. Konfirmasi diperlukan
                        sebelum Anda dapat berlangganan.
                    </p>
                </div>
            </div>

            <Button
                variant="outline"
                size="sm"
                className="shrink-0 border-amber-300 bg-white hover:bg-amber-100"
                disabled={processing || sent}
                onClick={() => post(route('verification.send'), { preserveScroll: true, onSuccess: () => setSent(true) })}
            >
                {processing ? <Loader2 className="h-4 w-4 animate-spin" /> : null}
                {sent ? 'Email sent' : 'Resend email'}
            </Button>
        </div>
    );
}

/**
 * THE UNSUBSCRIBED STATE, stated plainly.
 *
 * Steel, not amber. Neutral, not a warning. The heading says what is
 * true; the plans below are an invitation, not a gate.
 */
function NoSubscriptionPanel({ plans, pendingOrder, canSubscribe }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">No active subscription</CardTitle>
                <p className="mt-1 text-sm leading-relaxed text-graphite-500">
                    Akun Anda aktif, tetapi belum terhubung ke organisasi mana pun. Pilih paket untuk
                    membuat ruang kerja IOMS bagi perusahaan Anda.
                </p>
            </CardHeader>

            <CardContent className="space-y-4">
                {/* Somebody who abandoned checkout can pick up exactly where
                    they stopped, rather than starting again and leaving a
                    second orphan order behind. */}
                {pendingOrder && (
                    <div className="flex flex-col gap-2 rounded-lg border border-brand-200 bg-brand-50/60 p-3 sm:flex-row sm:items-center sm:justify-between">
                        <div className="min-w-0">
                            <p className="text-[13px] font-semibold text-navy-900">Order in progress</p>
                            <p className="mt-0.5 text-xs text-graphite-600">
                                {pendingOrder.plan} &middot; {pendingOrder.organization} &middot;{' '}
                                <span className="font-mono">{pendingOrder.reference}</span>
                            </p>
                        </div>
                        <Button size="sm" asChild className="shrink-0">
                            <Link href={pendingOrder.resume_url}>Resume <ArrowRight className="h-4 w-4" /></Link>
                        </Button>
                    </div>
                )}

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    {plans.map((plan) => (
                        <div key={plan.slug} className="rounded-lg border border-steel-200 bg-white p-3">
                            <p className="text-[13px] font-semibold text-navy-900">{plan.name}</p>
                            <p className="mt-0.5 text-xs text-graphite-500">
                                {plan.monthly?.formatted}
                                {/* A custom plan has no amount and formats as
                                    "Hubungi Kami" -- appending "/bln" to that
                                    would read as a price. */}
                                {plan.monthly?.amount !== null && <span className="text-graphite-400">/bln</span>}
                            </p>
                        </div>
                    ))}
                </div>

                <div className="flex flex-col gap-2 sm:flex-row">
                    <Button asChild disabled={!canSubscribe} className="sm:w-auto">
                        <Link href={route('subscribe.setup')}>Choose a plan <ArrowRight className="h-4 w-4" /></Link>
                    </Button>
                    <Button variant="outline" asChild className="sm:w-auto">
                        <a href={route('pricing')}>Compare plans <ExternalLink className="h-3.5 w-3.5" /></a>
                    </Button>
                </div>

                {!canSubscribe && (
                    <p className="text-xs text-graphite-500">
                        Konfirmasi alamat email Anda terlebih dahulu untuk dapat berlangganan.
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

/** Shown instead, once the account actually has an organization. */
function OrganizationPanel({ organization }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                    <Building2 className="h-4 w-4 text-graphite-400" aria-hidden="true" /> Organization
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                <FieldGrid columns={2}>
                    <Field label="Organization" value={organization?.name} emphasis />
                    <Field label="Operating Unit" value={organization?.company} />
                </FieldGrid>
                <div className="flex flex-col gap-2 sm:flex-row">
                    <Button asChild><Link href={route('dashboard')}>Open IOMS <ArrowRight className="h-4 w-4" /></Link></Button>
                    <Button variant="outline" asChild><Link href={route('subscription.billing')}>Billing &amp; plan</Link></Button>
                </div>
            </CardContent>
        </Card>
    );
}

function ProfileCard({ account }) {
    const { version } = usePage().props;
    const supportEmail = version?.support_email || 'support@iomsuite.com';
    const { data, setData, put, processing, errors } = useForm({ name: account.name });

    return (
        <Card>
            <CardHeader><CardTitle className="text-sm">Profile</CardTitle></CardHeader>
            <CardContent className="space-y-3">
                <form
                    onSubmit={(e) => { e.preventDefault(); put(route('account.profile.update'), { preserveScroll: true }); }}
                    className="space-y-3"
                >
                    <div className="space-y-1.5">
                        <Label htmlFor="name">Name</Label>
                        <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                        {errors.name && <p className="text-xs text-red-600">{errors.name}</p>}
                    </div>
                    <Button type="submit" size="sm" variant="outline" disabled={processing}>Save</Button>
                </form>

                <div className="border-t border-graphite-100 pt-3">
                    <FieldGrid columns={1}>
                        <Field
                            label="Email"
                            value={
                                <span className="inline-flex items-center gap-1.5">
                                    {account.email}
                                    {account.email_verified
                                        ? <BadgeCheck className="h-3.5 w-3.5 text-success" aria-label="Confirmed" />
                                        : null}
                                </span>
                            }
                        />
                    </FieldGrid>
                    {/* The address is not editable here, deliberately:
                        changing it would invalidate the verification it
                        already carries and is a security operation, not a
                        profile edit. */}
                    <p className="mt-1 text-[11px] leading-relaxed text-graphite-500">
                        Untuk mengubah alamat email, hubungi{' '}
                        <a href={`mailto:${supportEmail}`} className="text-brand-600 hover:underline">{supportEmail}</a>.
                    </p>
                </div>
            </CardContent>
        </Card>
    );
}

function SecurityCard({ account, googleEnabled }) {
    const { data, setData, put, processing, errors, reset } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-sm">
                    <ShieldCheck className="h-4 w-4 text-graphite-400" aria-hidden="true" /> Security
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                {/* Sign-in methods, stated as facts. An account may hold
                    either, or both. */}
                <ul className="space-y-1.5">
                    <li className="flex items-center gap-2 text-xs text-graphite-700">
                        <CheckCircle2 className={account.has_password ? 'h-3.5 w-3.5 text-success' : 'h-3.5 w-3.5 text-graphite-300'} aria-hidden="true" />
                        Password sign-in {account.has_password ? 'enabled' : 'not set'}
                    </li>
                    {googleEnabled && (
                        <li className="flex items-center gap-2 text-xs text-graphite-700">
                            <CheckCircle2 className={account.google_linked ? 'h-3.5 w-3.5 text-success' : 'h-3.5 w-3.5 text-graphite-300'} aria-hidden="true" />
                            Google sign-in {account.google_linked ? 'linked' : 'not linked'}
                        </li>
                    )}
                </ul>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        put(route('account.password.update'), { preserveScroll: true, onSuccess: () => reset() });
                    }}
                    className="space-y-3 border-t border-graphite-100 pt-3"
                >
                    {/* Only asked for when there IS one to prove. A
                        Google-only account has no password to confirm, and
                        requiring one would make it impossible to add. */}
                    {account.has_password && (
                        <div className="space-y-1.5">
                            <Label htmlFor="current_password">Current password</Label>
                            <Input
                                id="current_password"
                                type="password"
                                autoComplete="current-password"
                                value={data.current_password}
                                onChange={(e) => setData('current_password', e.target.value)}
                            />
                            {errors.current_password && <p className="text-xs text-red-600">{errors.current_password}</p>}
                        </div>
                    )}

                    <div className="space-y-1.5">
                        <Label htmlFor="new_password">{account.has_password ? 'New password' : 'Set a password'}</Label>
                        <Input
                            id="new_password"
                            type="password"
                            autoComplete="new-password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                        />
                        {errors.password && <p className="text-xs text-red-600">{errors.password}</p>}
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="new_password_confirmation">Confirm</Label>
                        <Input
                            id="new_password_confirmation"
                            type="password"
                            autoComplete="new-password"
                            value={data.password_confirmation}
                            onChange={(e) => setData('password_confirmation', e.target.value)}
                        />
                    </div>

                    <Button type="submit" size="sm" variant="outline" disabled={processing}>
                        {account.has_password ? 'Change password' : 'Enable password sign-in'}
                    </Button>
                </form>

                {googleEnabled && account.google_linked && (
                    <div className="border-t border-graphite-100 pt-3">
                        <Button
                            variant="ghost"
                            size="sm"
                            className="text-red-600"
                            onClick={() => router.delete(route('account.google.unlink'), { preserveScroll: true })}
                        >
                            Remove Google sign-in
                        </Button>
                        {!account.has_password && (
                            <p className="mt-1 text-[11px] leading-relaxed text-graphite-500">
                                Setel kata sandi terlebih dahulu &mdash; tanpa itu, akun ini tidak akan bisa diakses.
                            </p>
                        )}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
