/* v2.141.0 -- split out of Pages/Settings/Index.jsx (M0, Settings architecture). Behaviour unchanged. */
import { useForm } from '@inertiajs/react';
import { useRef } from 'react';
import ImageUploadField from '@/Components/shared/ImageUploadField';
import { Input } from '@/Components/ui/input';
import { AppWindow, FileSignature, Palette } from 'lucide-react';
import { FormSection, FormField, FormActions, ErrorSummary } from '@/Components/shared/form';
import { useUnsavedChanges } from '@/lib/useUnsavedChanges';

/**
 * v2.65.0 -- THE CONFIGURATION CASE for the Form Experience System.
 *
 * Configuration is not master data and should not read like it. What a
 * settings form owes the user is CONSEQUENCE: not "what is this field"
 * but "what will change if I set it". This tab held seventeen fields and
 * four uploads in one flat `max-w-lg` card, and mixed two genuinely
 * different concerns the code comments already distinguished but the
 * layout did not -- how the APPLICATION is branded, and what appears on
 * GENERATED DOCUMENTS as the legal letterhead.
 *
 * They are now three sections, each stating what it affects. Nothing was
 * added or removed; the same seventeen fields post to the same route.
 *
 * IT ALSO COULD NOT DISPLAY A SINGLE VALIDATION ERROR: `errors` was never
 * destructured from `useForm`, so a server rejection on the email or
 * website field produced a form that silently declined to save. Now
 * wired throughout, plus a summary.
 *
 * Every field here is genuinely optional except the application name,
 * which is why almost nothing is marked required -- and that is now
 * stated by the system rather than by "(optional)" appended to fourteen
 * separate labels.
 */
export default function BrandingTab({ company }) {
    const initial = useRef(null);

    const { data, setData, post, processing, errors } = useForm({
        company_name: company.name,
        company_subtitle: company.subtitle || '',
        company_short_name: company.short_name || '',
        footer_copyright: company.footer_copyright || '',
        company_address: company.address || '',
        company_phone: company.phone || '',
        company_email: company.email || '',
        company_website: company.website || '',
        // v2.51.0 -- document identity. These feed the shared letterhead
        // every generated PDF now uses (DocumentEngine::identity()), which
        // is why the section below is labelled as document identity rather
        // than being mixed into app branding.
        company_legal_name: company.legal_name || '',
        company_city: company.city || '',
        company_province: company.province || '',
        company_postal_code: company.postal_code || '',
        company_country: company.country || '',
        company_tax_id: company.tax_id || '',
        company_business_id: company.business_id || '',
        company_industry: company.industry || '',
        brand_color: company.brand_color || '#2563eb',
        logo: null,
        favicon: null,
        wordmark: null,
        brand_icon: null,
        // Explicit removal flags -- a file input is simply absent when
        // nothing is chosen, so the server cannot otherwise tell "no upload"
        // from "delete the saved one".
        remove_logo: false,
        remove_favicon: false,
        remove_wordmark: false,
        remove_brand_icon: false,
    });

    if (initial.current === null) initial.current = { ...data };

    const { release } = useUnsavedChanges(data, initial.current, !processing);

    function submit(e) {
        e.preventDefault();
        release();
        post(route('settings.company'), { forceFormData: true });
    }

    return (
        <form onSubmit={submit} className="max-w-3xl space-y-6">
            <ErrorSummary errors={errors} />

            <FormSection
                title="Application identity"
                description="What this workspace calls itself, in the sidebar and the browser tab."
                icon={AppWindow}
            >
                <FormField label="Application name" name="company_name" required error={errors.company_name}>
                    {(control) => (
                        <Input {...control} value={data.company_name} onChange={(e) => setData('company_name', e.target.value)} />
                    )}
                </FormField>

                <FormField label="Subtitle" name="company_subtitle" error={errors.company_subtitle}>
                    {(control) => (
                        <Input {...control} value={data.company_subtitle} onChange={(e) => setData('company_subtitle', e.target.value)} placeholder="Industrial Operations Platform" />
                    )}
                </FormField>

                <FormField label="Short name" name="company_short_name" error={errors.company_short_name} hint="Used where space is tight, such as the collapsed sidebar.">
                    {(control) => (
                        <Input {...control} value={data.company_short_name} onChange={(e) => setData('company_short_name', e.target.value)} placeholder="e.g. GAJ" maxLength={50} />
                    )}
                </FormField>

                <FormField label="Footer copyright" name="footer_copyright" error={errors.footer_copyright} hint="Leave blank to use the default.">
                    {(control) => (
                        <Input {...control} value={data.footer_copyright} onChange={(e) => setData('footer_copyright', e.target.value)} />
                    )}
                </FormField>
            </FormSection>

            <FormSection
                title="Document identity"
                description="The letterhead on every document IOMS generates for you: permits, purchase orders, receipts and work orders."
                icon={FileSignature}
            >
                <FormField label="Legal company name" name="company_legal_name" error={errors.company_legal_name} className="sm:col-span-2">
                    {(control) => (
                        <Input {...control} value={data.company_legal_name} onChange={(e) => setData('company_legal_name', e.target.value)} placeholder="PT Contoh Industri Nusantara" />
                    )}
                </FormField>

                <FormField label="Address" name="company_address" error={errors.company_address} className="sm:col-span-2">
                    {(control) => (
                        <Input {...control} value={data.company_address} onChange={(e) => setData('company_address', e.target.value)} placeholder="Street address" />
                    )}
                </FormField>

                <FormField label="City" name="company_city" error={errors.company_city}>
                    {(control) => <Input {...control} value={data.company_city} onChange={(e) => setData('company_city', e.target.value)} />}
                </FormField>

                <FormField label="Province" name="company_province" error={errors.company_province}>
                    {(control) => <Input {...control} value={data.company_province} onChange={(e) => setData('company_province', e.target.value)} />}
                </FormField>

                <FormField label="Postal code" name="company_postal_code" error={errors.company_postal_code}>
                    {(control) => <Input {...control} value={data.company_postal_code} onChange={(e) => setData('company_postal_code', e.target.value)} />}
                </FormField>

                <FormField label="Country" name="company_country" error={errors.company_country}>
                    {(control) => <Input {...control} value={data.company_country} onChange={(e) => setData('company_country', e.target.value)} placeholder="Indonesia" />}
                </FormField>

                <FormField label="NPWP" name="company_tax_id" error={errors.company_tax_id}>
                    {(control) => <Input {...control} value={data.company_tax_id} onChange={(e) => setData('company_tax_id', e.target.value)} />}
                </FormField>

                <FormField label="NIB" name="company_business_id" error={errors.company_business_id}>
                    {(control) => <Input {...control} value={data.company_business_id} onChange={(e) => setData('company_business_id', e.target.value)} />}
                </FormField>

                <FormField label="Phone" name="company_phone" error={errors.company_phone}>
                    {(control) => <Input {...control} type="tel" value={data.company_phone} onChange={(e) => setData('company_phone', e.target.value)} />}
                </FormField>

                <FormField label="Email" name="company_email" error={errors.company_email}>
                    {(control) => <Input {...control} type="email" value={data.company_email} onChange={(e) => setData('company_email', e.target.value)} />}
                </FormField>

                <FormField label="Website" name="company_website" error={errors.company_website}>
                    {(control) => <Input {...control} value={data.company_website} onChange={(e) => setData('company_website', e.target.value)} placeholder="https://" />}
                </FormField>
            </FormSection>

            <FormSection
                title="Brand assets"
                description="Shown in the sidebar and on generated documents. Leave empty to keep the standard IOMS mark."
                icon={Palette}
            >
                <FormField label="Brand colour" name="brand_color" error={errors.brand_color}>
                    {(control) => (
                        <div className="flex items-center gap-2">
                            <input
                                type="color"
                                value={data.brand_color}
                                onChange={(e) => setData('brand_color', e.target.value)}
                                className="h-9 w-10 cursor-pointer rounded border border-graphite-200"
                                aria-label="Pick brand colour"
                            />
                            <Input {...control} value={data.brand_color} onChange={(e) => setData('brand_color', e.target.value)} className="w-28" />
                        </div>
                    )}
                </FormField>

                <div className="sm:col-span-2 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <ImageUploadField
                        label="Logo (SVG or PNG)"
                        existingUrl={data.remove_logo ? null : company.logo_url}
                        file={data.logo}
                        onChange={(file) => setData({ ...data, logo: file, remove_logo: false })}
                        onRemoveExisting={() => setData('remove_logo', true)}
                        accept="image/png,image/jpeg,image/jpg,image/webp,image/svg+xml"
                        shape="square"
                        error={errors.logo}
                    />
                    <ImageUploadField
                        label="Favicon"
                        existingUrl={data.remove_favicon ? null : company.favicon_url}
                        file={data.favicon}
                        onChange={(file) => setData({ ...data, favicon: file, remove_favicon: false })}
                        onRemoveExisting={() => setData('remove_favicon', true)}
                        accept="image/png,image/x-icon,image/svg+xml"
                        shape="square"
                        error={errors.favicon}
                    />
                    {/* v2.41.0 -- the wordmark/icon keys HandleInertiaRequests has
                        read since v1.5.3 but nothing could write. Both are genuine
                        optional overrides: with no upload, BrandWordmark renders the
                        official IOMS lockup (v2.72.0). */}
                    <ImageUploadField
                        label="Wordmark (horizontal logotype)"
                        existingUrl={data.remove_wordmark ? null : company.wordmark_url}
                        file={data.wordmark}
                        onChange={(file) => setData({ ...data, wordmark: file, remove_wordmark: false })}
                        onRemoveExisting={() => setData('remove_wordmark', true)}
                        accept="image/png,image/jpeg,image/jpg,image/webp,image/svg+xml"
                        shape="square"
                        error={errors.wordmark}
                    />
                    <ImageUploadField
                        label="Brand icon (square mark)"
                        existingUrl={data.remove_brand_icon ? null : company.brand_icon_url}
                        file={data.brand_icon}
                        onChange={(file) => setData({ ...data, brand_icon: file, remove_brand_icon: false })}
                        onRemoveExisting={() => setData('remove_brand_icon', true)}
                        accept="image/png,image/jpeg,image/jpg,image/webp,image/svg+xml"
                        shape="square"
                        error={errors.brand_icon}
                    />
                </div>
            </FormSection>

            <FormActions
                submitLabel="Save changes"
                processing={processing}
                note="Applies to every user in this workspace and to documents generated from now on."
            />
        </form>
    );
}
