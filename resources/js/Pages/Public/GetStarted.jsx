import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Check, ArrowRight, Building2, CreditCard, KeyRound, ShieldCheck, Mail, AlertCircle } from 'lucide-react';
import PublicLayout from '@/Layouts/PublicLayout';
import PublicPageHero from '@/Components/shared/PublicPageHero';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { PasswordInput } from '@/Components/ui/password-input';
import ImageUploadField from '@/Components/shared/ImageUploadField';
import { cn } from '@/lib/utils';

/**
 * v2.51.0 -- real self-service onboarding.
 *
 * WHAT THIS REPLACES: a "Request this plan" button that opened a mailto:
 * link. On most machines that produces the operating system's "choose an
 * app to open this link" dialog, and on a machine with no mail client
 * configured it produces nothing at all. Asking a prospect to compose an
 * email to buy software is not an acquisition flow.
 *
 * WHAT IT DOES NOW: creates a pending registration -- an account, a
 * company identity and a chosen plan -- then verifies the email and moves
 * to payment. It creates NO tenant, NO company row and NO user account.
 * Those exist only after the payment provider confirms payment to our
 * server, which is why step 04 below is stated the way it is.
 *
 * The form is deliberately one page rather than a wizard. This is a B2B
 * purchase made by someone with the company details already in front of
 * them; splitting eight fields across four screens adds ceremony, not
 * clarity. Sections give it structure instead.
 */
export default function GetStarted({ plans = [], selectedPlan, billingCycle, industries = [], contactEmail }) {
    const [yearly, setYearly] = useState(billingCycle !== 'monthly');

    const { data, setData, post, processing, errors } = useForm({
        contact_name: '',
        contact_email: '',
        contact_phone: '',
        password: '',
        password_confirmation: '',

        company_legal_name: '',
        company_display_name: '',
        company_industry: '',
        company_address: '',
        company_city: '',
        company_province: '',
        company_postal_code: '',
        company_country: 'Indonesia',
        company_phone: '',
        company_email: '',
        company_tax_id: '',
        company_business_id: '',
        billing_email: '',
        logo: null,

        plan: selectedPlan || plans[1]?.slug || plans[0]?.slug || '',
        billing_cycle: billingCycle !== 'monthly' ? 'yearly' : 'monthly',
        terms: false,
    });

    const plan = plans.find((p) => p.slug === data.plan) || null;
    const price = plan ? (yearly ? plan.yearly : plan.monthly) : null;
    // Server-derived, from the plan's own two prices -- never computed here.
    const saving = plan?.annual_saving ?? null;

    const chooseCycle = (isYearly) => {
        setYearly(isYearly);
        setData('billing_cycle', isYearly ? 'yearly' : 'monthly');
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('register.store'), { forceFormData: true, preserveScroll: true });
    };

    // Each step says plainly what happens and who does it. Step 04 is the
    // one that matters: activation follows a verified payment, not a
    // browser landing on a success page.
    const steps = [
        { icon: Mail, title: 'Confirm your email', body: 'We send a confirmation link to the address you register. It becomes both your administrator contact and your billing contact.' },
        { icon: CreditCard, title: 'Pay for your plan', body: 'An invoice is issued for the cycle you choose and paid through our payment provider. Your card details never reach IOMS.' },
        { icon: Building2, title: 'Your workspace is created', body: 'Your organization, first operating unit, administrator account and permissions are set up — with your own company identity on them.' },
        { icon: KeyRound, title: 'Sign in to IOMS', body: 'Use the password you set here. IOMS never sends a password by email.' },
    ];

    return (
        <PublicLayout>
            <Head title="Get Started" />

            <PublicPageHero
                eyebrow="Get Started"
                title="Set IOMS up for your operation."
                subtitle="Create your account and company, choose a plan, then complete payment. Your workspace is prepared as soon as the payment is confirmed."
                size="sm"
            />

            <section className="bg-graphite-100 py-12 sm:py-16">
                <form
                    onSubmit={submit}
                    className="mx-auto grid max-w-6xl gap-6 px-4 sm:px-6 lg:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)] lg:items-start"
                >
                    {/* ------------------------------------------------ */}
                    {/* Left: the actual form                             */}
                    {/* ------------------------------------------------ */}
                    <div className="space-y-6">
                        <FormSection
                            title="Your account"
                            hint="This account becomes the administrator of your IOMS workspace."
                        >
                            <Field label="Full name" required error={errors.contact_name} className="sm:col-span-2">
                                <Input value={data.contact_name} onChange={(e) => setData('contact_name', e.target.value)} autoComplete="name" />
                            </Field>
                            <Field label="Work email" required error={errors.contact_email}>
                                <Input type="email" value={data.contact_email} onChange={(e) => setData('contact_email', e.target.value)} autoComplete="email" />
                            </Field>
                            <Field label="Phone number" error={errors.contact_phone}>
                                <Input value={data.contact_phone} onChange={(e) => setData('contact_phone', e.target.value)} autoComplete="tel" />
                            </Field>
                            <Field label="Password" required error={errors.password} hint="At least 8 characters.">
                                <PasswordInput value={data.password} onChange={(e) => setData('password', e.target.value)} autoComplete="new-password" />
                            </Field>
                            <Field label="Confirm password" required>
                                <PasswordInput value={data.password_confirmation} onChange={(e) => setData('password_confirmation', e.target.value)} autoComplete="new-password" />
                            </Field>
                        </FormSection>

                        <FormSection
                            title="Your company"
                            hint="Used as your workspace identity and as the letterhead on documents IOMS generates."
                        >
                            <Field label="Legal company name" required error={errors.company_legal_name} className="sm:col-span-2">
                                <Input value={data.company_legal_name} onChange={(e) => setData('company_legal_name', e.target.value)} placeholder="PT Contoh Industri Nusantara" />
                            </Field>
                            <Field label="Display name" error={errors.company_display_name} hint="Shown in the app if different from the legal name." className="sm:col-span-2">
                                <Input value={data.company_display_name} onChange={(e) => setData('company_display_name', e.target.value)} placeholder="Contoh Industri" />
                            </Field>

                            <Field label="Industry" error={errors.company_industry} className="sm:col-span-2">
                                <select
                                    value={data.company_industry}
                                    onChange={(e) => setData('company_industry', e.target.value)}
                                    className="h-9 w-full rounded-md border border-steel-200 bg-white px-3 text-sm text-navy-900 shadow-none focus:outline-none focus:ring-1 focus:ring-brand-500"
                                >
                                    <option value="">Select an industry</option>
                                    {industries.map((i) => <option key={i} value={i}>{i}</option>)}
                                </select>
                            </Field>

                            <Field label="Address" required error={errors.company_address} className="sm:col-span-2">
                                <Input value={data.company_address} onChange={(e) => setData('company_address', e.target.value)} />
                            </Field>
                            <Field label="City" required error={errors.company_city}>
                                <Input value={data.company_city} onChange={(e) => setData('company_city', e.target.value)} />
                            </Field>
                            <Field label="Province" required error={errors.company_province}>
                                <Input value={data.company_province} onChange={(e) => setData('company_province', e.target.value)} />
                            </Field>
                            <Field label="Postal code" error={errors.company_postal_code}>
                                <Input value={data.company_postal_code} onChange={(e) => setData('company_postal_code', e.target.value)} />
                            </Field>
                            <Field label="Country" error={errors.company_country}>
                                <Input value={data.company_country} onChange={(e) => setData('company_country', e.target.value)} />
                            </Field>
                            <Field label="Company phone" error={errors.company_phone}>
                                <Input value={data.company_phone} onChange={(e) => setData('company_phone', e.target.value)} />
                            </Field>
                            <Field label="Company email" error={errors.company_email}>
                                <Input type="email" value={data.company_email} onChange={(e) => setData('company_email', e.target.value)} />
                            </Field>

                            {/* Optional Indonesian business identifiers. Not
                                required to operate IOMS -- collected because
                                they belong on a formal document header, and
                                left blank without consequence if a customer
                                does not want to provide them yet. */}
                            <Field label="NPWP" error={errors.company_tax_id} hint="Optional — appears on generated documents.">
                                <Input value={data.company_tax_id} onChange={(e) => setData('company_tax_id', e.target.value)} />
                            </Field>
                            <Field label="NIB" error={errors.company_business_id} hint="Optional.">
                                <Input value={data.company_business_id} onChange={(e) => setData('company_business_id', e.target.value)} />
                            </Field>

                            <Field label="Billing email" error={errors.billing_email} hint="Leave blank to use your account email." className="sm:col-span-2">
                                <Input type="email" value={data.billing_email} onChange={(e) => setData('billing_email', e.target.value)} />
                            </Field>

                            <div className="sm:col-span-2">
                                <ImageUploadField
                                    label="Company logo"
                                    file={data.logo}
                                    onChange={(file) => setData('logo', file)}
                                    error={errors.logo}
                                />
                                <p className="mt-1 text-[11px] text-graphite-400">
                                    Optional. Appears in your workspace and as the letterhead mark on generated
                                    documents. PNG, JPG, SVG or WEBP, up to 2&nbsp;MB.
                                </p>
                            </div>
                        </FormSection>

                        <div className="rounded-xl border border-steel-200/70 bg-white p-5 shadow-panel">
                            <label className="flex items-start gap-3">
                                <input
                                    type="checkbox"
                                    checked={data.terms}
                                    onChange={(e) => setData('terms', e.target.checked)}
                                    className="mt-0.5 h-4 w-4 rounded border-steel-300 text-brand-600 focus:ring-brand-500"
                                />
                                <span className="text-xs leading-relaxed text-graphite-600">
                                    I agree to the{' '}
                                    <Link href={route('legal.terms')} className="font-medium text-brand-700 hover:underline">Terms of Service</Link>
                                    {' '}and{' '}
                                    <Link href={route('legal.privacy')} className="font-medium text-brand-700 hover:underline">Privacy Policy</Link>,
                                    and I am authorised to register this company.
                                </span>
                            </label>
                            {errors.terms && <p className="mt-2 text-xs text-danger">{errors.terms}</p>}
                        </div>
                    </div>

                    {/* ------------------------------------------------ */}
                    {/* Right: plan summary + what happens next           */}
                    {/* ------------------------------------------------ */}
                    <div className="space-y-6 lg:sticky lg:top-20">
                        <div className="rounded-xl border border-steel-200/70 bg-white p-6 shadow-panel">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <h2 className="text-[15px] font-semibold tracking-tight text-navy-900">Your plan</h2>
                                <div className="inline-flex items-center gap-1 rounded-full border border-steel-200 bg-steel-50 p-0.5" role="group" aria-label="Billing cycle">
                                    {[{ k: false, l: 'Monthly' }, { k: true, l: 'Annual' }].map((o) => (
                                        <button
                                            key={o.l}
                                            type="button"
                                            onClick={() => chooseCycle(o.k)}
                                            aria-pressed={yearly === o.k}
                                            className={cn(
                                                'rounded-full px-3 py-1 text-[11px] font-semibold transition-colors',
                                                yearly === o.k ? 'bg-navy-900 text-white' : 'text-graphite-600 hover:text-navy-800'
                                            )}
                                        >
                                            {o.l}
                                        </button>
                                    ))}
                                </div>
                            </div>

                            <div className="mt-4 space-y-2.5">
                                {plans.map((p) => {
                                    const active = p.slug === data.plan;
                                    const amount = yearly ? p.yearly : p.monthly;

                                    return (
                                        <button
                                            key={p.slug}
                                            type="button"
                                            onClick={() => setData('plan', p.slug)}
                                            aria-pressed={active}
                                            className={cn(
                                                'flex w-full items-center gap-3 rounded-xl border p-3.5 text-left transition-all',
                                                active
                                                    ? 'border-brand-300 bg-gradient-to-b from-steel-100/80 via-white to-white shadow-card'
                                                    : 'border-steel-100 bg-white hover:border-steel-200 hover:bg-steel-50/60'
                                            )}
                                        >
                                            <span
                                                className={cn(
                                                    'flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 transition-colors',
                                                    active ? 'border-brand-600 bg-brand-600 text-white' : 'border-steel-300'
                                                )}
                                            >
                                                {active && <Check className="h-3 w-3" />}
                                            </span>
                                            <span className="min-w-0 flex-1">
                                                <span className="block text-sm font-semibold text-navy-900">{p.name}</span>
                                                <span className="block truncate text-[11px] text-graphite-500">
                                                    {p.max_users ? `${p.max_users} user accounts` : 'Highest capacity'}
                                                    {p.max_companies ? ` · ${p.max_companies} Operating Unit${p.max_companies > 1 ? 's' : ''}` : ' · Multiple Operating Units'}
                                                </span>
                                            </span>
                                            <span className="shrink-0 text-right">
                                                <span className="block text-sm font-semibold text-navy-900">{amount?.formatted ?? '—'}</span>
                                                <span className="block text-[10px] uppercase tracking-wide text-graphite-400">/{yearly ? 'year' : 'month'}</span>
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>
                            {errors.plan && <p className="mt-2 text-xs text-danger">{errors.plan}</p>}

                            {plan && (
                                <ul className="mt-4 space-y-1.5 border-t border-steel-100 pt-4">
                                    {(plan.workspaces ?? []).slice(0, 5).map((w) => (
                                        <li key={w} className="flex items-start gap-2 text-xs text-graphite-600">
                                            <Check className="mt-0.5 h-3.5 w-3.5 shrink-0 text-success" />
                                            <span>{w}</span>
                                        </li>
                                    ))}
                                </ul>
                            )}

                            <div className="mt-5 border-t border-steel-100 pt-5">
                                <div className="flex items-baseline justify-between">
                                    <span className="text-xs font-medium uppercase tracking-wide text-graphite-400">
                                        {yearly ? 'Annual billing' : 'Monthly billing'}
                                    </span>
                                    <span className="text-lg font-semibold tracking-tight text-navy-900">
                                        {price?.formatted ?? '—'}
                                    </span>
                                </div>

                                {/* v2.56.0: the annual price was shown as a bare figure, so a
                                    buyer choosing a cycle had to work out for themselves that
                                    it was already discounted. The comparison is stated instead,
                                    from the plan's own two prices (PricingService::annualSaving). */}
                                {yearly && saving && (
                                    <div className="mt-3 rounded-lg border border-success/20 bg-success/[0.06] px-3 py-2.5">
                                        <div className="flex items-baseline justify-between text-[11px] text-graphite-600">
                                            <span>12 × {plan.monthly.formatted}</span>
                                            <span className="line-through">{saving.monthly_equivalent_formatted}</span>
                                        </div>
                                        <div className="mt-1 flex items-baseline justify-between text-xs font-semibold text-success">
                                            <span>You save</span>
                                            <span>{saving.formatted} · {saving.percent}%</span>
                                        </div>
                                    </div>
                                )}

                                <Button type="submit" className="mt-4 w-full" disabled={processing || !plan}>
                                    {processing ? 'Creating your registration…' : <>Continue to payment <ArrowRight className="h-4 w-4" /></>}
                                </Button>

                                <p className="mt-2.5 flex items-start gap-1.5 text-center text-[11px] leading-relaxed text-graphite-500">
                                    <ShieldCheck className="mt-px h-3.5 w-3.5 shrink-0 text-graphite-400" />
                                    <span className="text-left">
                                        Your subscription becomes active only after the payment provider confirms
                                        payment. This form charges nothing.
                                    </span>
                                </p>
                            </div>
                        </div>

                        <div className="rounded-xl border border-steel-200/70 bg-gradient-to-b from-steel-100/70 via-white to-white p-6 shadow-panel">
                            <h2 className="text-[15px] font-semibold tracking-tight text-navy-900">What happens next</h2>
                            <ol className="mt-4 space-y-4">
                                {steps.map((s, i) => (
                                    <li key={s.title} className="flex gap-3">
                                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-[10px] bg-gradient-to-br from-navy-800 to-brand-600 text-white shadow-[0_3px_8px_-3px_rgba(33,102,196,0.38)]">
                                            <s.icon className="h-4 w-4" />
                                        </span>
                                        <div className="min-w-0">
                                            <p className="text-[13px] font-semibold text-navy-900">
                                                <span className="mr-1.5 font-mono text-[11px] text-graphite-400">0{i + 1}</span>
                                                {s.title}
                                            </p>
                                            <p className="mt-0.5 text-xs leading-relaxed text-graphite-500">{s.body}</p>
                                        </div>
                                    </li>
                                ))}
                            </ol>

                            <div className="mt-6 rounded-lg border border-steel-100 bg-white p-3.5">
                                <p className="text-xs leading-relaxed text-graphite-600">
                                    <span className="font-semibold text-navy-800">Already an IOMS customer?</span>{' '}
                                    <Link href={route('login')} className="font-medium text-brand-700 hover:underline">Sign in here</Link>
                                    {' '}— or{' '}
                                    <Link href={route('pricing')} className="font-medium text-brand-700 hover:underline">compare plans</Link>
                                    {' '}first.
                                </p>
                            </div>
                        </div>

                        {contactEmail && (
                            <p className="text-center text-xs text-graphite-500">
                                Need help choosing a plan?{' '}
                                <Link href={route('contact')} className="font-medium text-brand-700 hover:underline">Talk to us</Link>
                            </p>
                        )}
                    </div>
                </form>
            </section>
        </PublicLayout>
    );
}

/* ------------------------------------------------------------------ */
/* Local form primitives -- kept here because they exist to give ONE   */
/* long public form structure, not to become a second design system.   */
/* ------------------------------------------------------------------ */
function FormSection({ title, hint, children }) {
    return (
        <div className="rounded-xl border border-steel-200/70 bg-white p-6 shadow-panel">
            <h2 className="text-[15px] font-semibold tracking-tight text-navy-900">{title}</h2>
            {hint && <p className="mt-1 text-xs leading-relaxed text-graphite-500">{hint}</p>}
            <div className="mt-5 grid gap-4 sm:grid-cols-2">{children}</div>
        </div>
    );
}

function Field({ label, required, error, hint, className, children }) {
    return (
        <div className={className}>
            <Label className="mb-1.5 block">
                {label}
                {required && <span className="ml-0.5 text-danger">*</span>}
            </Label>
            {children}
            {hint && !error && <p className="mt-1 text-[11px] text-graphite-400">{hint}</p>}
            {error && (
                <p className="mt-1 flex items-center gap-1 text-[11px] text-danger">
                    <AlertCircle className="h-3 w-3 shrink-0" />
                    {error}
                </p>
            )}
        </div>
    );
}
