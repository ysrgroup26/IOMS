import { Head, useForm, Link } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { Label } from '@/Components/ui/label';
import { Card, CardContent } from '@/Components/ui/card';
import { FormSection, FormField, ErrorSummary, SearchableSelect } from '@/Components/shared/form';
import SubscribeShell from '@/Pages/Subscribe/Shell';
import { ArrowRight, ArrowLeft, Loader2, BadgeCheck } from 'lucide-react';

/**
 * v2.74.0 -- STEP 2 OF 4: THE ORGANIZATION.
 *
 * THE POINT OF THIS PAGE IS WHAT IT DOES NOT ASK FOR.
 *
 * The legacy `/get-started` form asked a stranger for their name, email,
 * password, company, address and plan in one pass, because there was no
 * account to draw on. Here there is: the person's name and email are
 * already known, already confirmed, and are SHOWN at the top as
 * established facts rather than rendered as editable inputs.
 *
 * Everything this form collects belongs to the BUSINESS -- the legal
 * entity that will be invoiced and whose workspace is about to be
 * created. That separation is the whole architecture of v2.74.0
 * expressed as a form: Account is who you are, Organization is what you
 * run, Subscription is what you bought.
 *
 * Nothing is provisioned by submitting this. It raises an ORDER, which
 * expires if abandoned.
 */
export default function SubscribeOrganization({ account, plan, cycle, amountFormatted, industries }) {
    const { data, setData, post, processing, errors } = useForm({
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
        contact_phone: '',
        billing_cycle: cycle,
        terms: false,
    });

    function submit(e) {
        e.preventDefault();
        post(route('subscribe.organization.store', plan.slug));
    }

    return (
        <SubscribeShell
            step={2}
            account={account}
            heading="Organization details"
            subheading="Data perusahaan yang akan ditagih dan yang ruang kerjanya akan dibuat."
            aside={<OrderAside plan={plan} cycle={cycle} amountFormatted={amountFormatted} />}
        >
            <Head title="Organization details" />

            <form onSubmit={submit} className="space-y-4">
                <ErrorSummary errors={errors} labels={ERROR_LABELS} />

                {/* THE ACCOUNT, RESTATED AND NOT RE-ASKED. Rendered as facts
                    with a confirmed badge, not as inputs -- an editable name
                    field here would let somebody raise an order in another
                    person's name, and it would undo the one thing this
                    release set out to fix. */}
                <Card>
                    <CardContent className="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div className="min-w-0">
                            <p className="text-[10px] font-semibold uppercase tracking-wide text-graphite-400">Account</p>
                            <p className="mt-0.5 text-[14px] font-medium text-navy-900">{account.name}</p>
                            <p className="flex items-center gap-1.5 text-xs text-graphite-500">
                                {account.email}
                                {account.email_verified && <BadgeCheck className="h-3.5 w-3.5 text-success" aria-label="Confirmed" />}
                            </p>
                        </div>
                        <p className="shrink-0 text-xs text-graphite-500">
                            Diambil dari akun Anda &mdash; tidak perlu diisi ulang.
                        </p>
                    </CardContent>
                </Card>

                <FormSection
                    title="Organization"
                    description="Nama badan usaha sesuai dokumen resmi, dan nama yang ingin ditampilkan di dalam aplikasi."
                >
                    <FormField label="Legal name" name="company_legal_name" required error={errors.company_legal_name}>
                        {(control) => (
                            <Input {...control} value={data.company_legal_name} onChange={(e) => setData('company_legal_name', e.target.value)} placeholder="PT Contoh Industri Nusantara" />
                        )}
                    </FormField>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <FormField label="Display name" name="company_display_name" error={errors.company_display_name} hint="Nama singkat yang tampil di dalam IOMS.">
                            {(control) => <Input {...control} value={data.company_display_name} onChange={(e) => setData('company_display_name', e.target.value)} placeholder="Contoh Industri" />}
                        </FormField>
                        <FormField label="Industry" name="company_industry" error={errors.company_industry}>
                            {(control) => (
                                <SearchableSelect
                                    {...control}
                                    value={data.company_industry}
                                    onChange={(v) => setData('company_industry', v)}
                                    options={industries.map((i) => ({ value: i, label: i }))}
                                    placeholder="Pilih industri"
                                    clearable
                                />
                            )}
                        </FormField>
                    </div>
                </FormSection>

                <FormSection title="Address" description="Alamat resmi perusahaan, digunakan pada faktur dan dokumen yang dihasilkan IOMS.">
                    <FormField label="Address" name="company_address" required error={errors.company_address}>
                        {(control) => <Textarea {...control} rows={2} value={data.company_address} onChange={(e) => setData('company_address', e.target.value)} />}
                    </FormField>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <FormField label="City" name="company_city" required error={errors.company_city}>
                            {(control) => <Input {...control} value={data.company_city} onChange={(e) => setData('company_city', e.target.value)} />}
                        </FormField>
                        <FormField label="Province" name="company_province" required error={errors.company_province}>
                            {(control) => <Input {...control} value={data.company_province} onChange={(e) => setData('company_province', e.target.value)} />}
                        </FormField>
                        <FormField label="Postal code" name="company_postal_code" error={errors.company_postal_code}>
                            {(control) => <Input {...control} value={data.company_postal_code} onChange={(e) => setData('company_postal_code', e.target.value)} />}
                        </FormField>
                        <FormField label="Country" name="company_country" error={errors.company_country}>
                            {(control) => <Input {...control} value={data.company_country} onChange={(e) => setData('company_country', e.target.value)} />}
                        </FormField>
                    </div>
                </FormSection>

                <FormSection title="Billing & contact" description="Ke mana faktur dikirim, dan nomor yang dapat dihubungi.">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <FormField label="Billing email" name="billing_email" error={errors.billing_email} hint={`Kosongkan untuk memakai ${account.email}.`}>
                            {(control) => <Input {...control} type="email" value={data.billing_email} onChange={(e) => setData('billing_email', e.target.value)} />}
                        </FormField>
                        <FormField label="Contact phone" name="contact_phone" error={errors.contact_phone}>
                            {(control) => <Input {...control} value={data.contact_phone} onChange={(e) => setData('contact_phone', e.target.value)} />}
                        </FormField>
                        <FormField label="Tax ID (NPWP)" name="company_tax_id" error={errors.company_tax_id}>
                            {(control) => <Input {...control} value={data.company_tax_id} onChange={(e) => setData('company_tax_id', e.target.value)} />}
                        </FormField>
                        <FormField label="Business ID (NIB)" name="company_business_id" error={errors.company_business_id}>
                            {(control) => <Input {...control} value={data.company_business_id} onChange={(e) => setData('company_business_id', e.target.value)} />}
                        </FormField>
                    </div>
                </FormSection>

                <label className="flex cursor-pointer items-start gap-2.5">
                    <input
                        type="checkbox"
                        checked={data.terms}
                        onChange={(e) => setData('terms', e.target.checked)}
                        className="mt-0.5 rounded border-graphite-300 text-brand-600 focus:ring-brand-500"
                    />
                    <span className="text-xs leading-relaxed text-graphite-600">
                        Saya menyetujui{' '}
                        <Link href={route('legal.terms')} className="font-medium text-brand-700 hover:underline">Syarat &amp; Ketentuan</Link>
                        {' '}dan{' '}
                        <Link href={route('legal.refunds')} className="font-medium text-brand-700 hover:underline">Kebijakan Pengembalian Dana</Link>.
                    </span>
                </label>
                {errors.terms && <p className="text-xs text-red-600">{errors.terms}</p>}

                <div className="flex flex-col gap-2 pt-1 sm:flex-row-reverse sm:justify-start">
                    <Button type="submit" disabled={processing}>
                        {processing ? <Loader2 className="h-4 w-4 animate-spin" /> : null}
                        Continue to order summary <ArrowRight className="h-4 w-4" />
                    </Button>
                    <Button variant="ghost" asChild>
                        <Link href={route('subscribe.plans')}><ArrowLeft className="h-4 w-4" /> Back to plans</Link>
                    </Button>
                </div>
            </form>
        </SubscribeShell>
    );
}

/** The plan and price, held in view while the form is filled in. */
function OrderAside({ plan, cycle, amountFormatted }) {
    return (
        <Card className="lg:sticky lg:top-6">
            <CardContent className="space-y-3 py-4">
                <p className="text-[10px] font-semibold uppercase tracking-wide text-graphite-400">Your plan</p>
                <div>
                    <p className="text-[15px] font-semibold text-navy-900">{plan.name}</p>
                    <p className="mt-0.5 text-xs text-graphite-500">
                        Ditagih {cycle === 'monthly' ? 'bulanan' : 'tahunan'}
                    </p>
                </div>

                <div className="border-t border-graphite-100 pt-3">
                    <div className="flex items-baseline justify-between">
                        <span className="text-xs text-graphite-500">Total</span>
                        <span className="text-[18px] font-semibold tracking-tight text-navy-900">{amountFormatted}</span>
                    </div>
                </div>

                <p className="text-[11px] leading-relaxed text-graphite-500">
                    Belum ada tagihan pada langkah ini. Faktur diterbitkan setelah Anda melanjutkan ke
                    ringkasan pesanan.
                </p>

                <Label className="sr-only">Billing cycle</Label>
            </CardContent>
        </Card>
    );
}

const ERROR_LABELS = {
    company_legal_name: 'Legal name',
    company_address: 'Address',
    company_city: 'City',
    company_province: 'Province',
    company_industry: 'Industry',
    billing_email: 'Billing email',
    terms: 'Terms',
};
