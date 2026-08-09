import { Head, Link } from '@inertiajs/react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { ArrowLeft, Pencil, Settings2 } from 'lucide-react';

const STATUS_VARIANT = {
    active: 'success',
    trial: 'default',
    suspended: 'destructive',
    expired: 'secondary',
};

function formatDate(value) {
    if (!value) return '—';
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

/**
 * Master -> Tenant Management. The Master-side, read-only Tenant Detail
 * view -- real data only (see PlatformController::show()'s own doc
 * comment). Deliberately does NOT let the Master "enter" this tenant as
 * if they were its Administrator (no session/tenant_id change, no
 * AuthenticatedLayout) -- this is an inspection view within the Platform
 * console, reachable and leavable ("Back to Tenants") without the Master
 * ever stopping being a Platform Super Admin. Actions here link only to
 * pages that actually exist (Edit reopens the same dialog as the
 * Tenants list via a query flag is NOT done here -- Edit stays on the
 * list page itself, this page links back to it plus Grants, matching
 * "don't invent a fake button" from the brief).
 */
export default function PlatformTenantDetail({ tenant, subscription, administrator }) {
    return (
        <PlatformLayout>
            <Head title={tenant.name} />

            <Link href={route('platform.tenants')} className="mb-4 inline-flex items-center gap-1.5 text-sm text-graphite-500 hover:text-graphite-700">
                <ArrowLeft className="h-4 w-4" /> Back to Tenants
            </Link>

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div className="flex items-center gap-2">
                        <h1 className="text-lg font-bold tracking-tight text-graphite-900">{tenant.name}</h1>
                        <Badge variant={STATUS_VARIANT[tenant.status] ?? 'secondary'}>{tenant.status}</Badge>
                    </div>
                    <p className="mt-1 text-sm text-graphite-500">/{tenant.slug} -- Tenant #{tenant.id}</p>
                </div>
                <div className="flex gap-2">
                    <Button variant="outline" size="sm" asChild>
                        <Link href={route('platform.tenants')}>
                            <Pencil className="h-3.5 w-3.5" /> Edit Tenant
                        </Link>
                    </Button>
                    <Button variant="outline" size="sm" asChild>
                        <Link href={route('platform.tenants.grants', tenant.id)}>
                            <Settings2 className="h-3.5 w-3.5" /> Manage Grants
                        </Link>
                    </Button>
                </div>
            </div>

            <div className="grid gap-4 md:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Tenant Information</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        <Row label="Name" value={tenant.name} />
                        <Row label="Slug" value={tenant.slug} />
                        <Row label="Status" value={<Badge variant={STATUS_VARIANT[tenant.status] ?? 'secondary'}>{tenant.status}</Badge>} />
                        <Row label="Companies" value={tenant.companies_count} />
                        <Row label="Users" value={tenant.users_count} />
                        <Row label="Created" value={formatDate(tenant.created_at)} />
                        <Row label="Last Updated" value={formatDate(tenant.updated_at)} />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Subscription</CardTitle>
                        <CardDescription>The package and billing period this tenant is on.</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        {subscription ? (
                            <>
                                <Row label="Package" value={subscription.package_name ?? '—'} />
                                <Row label="Subscription Status" value={subscription.status} />
                                <Row label="Billing Cycle" value={subscription.billing_cycle} />
                                <Row label="Starts" value={formatDate(subscription.starts_at)} />
                                <Row label="Ends" value={formatDate(subscription.ends_at)} />
                            </>
                        ) : (
                            <p className="text-graphite-400">No subscription on record for this tenant.</p>
                        )}
                    </CardContent>
                </Card>

                <Card className="md:col-span-2">
                    <CardHeader>
                        <CardTitle>Administrator</CardTitle>
                        <CardDescription>This tenant's own Super Admin account -- created together with the tenant.</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        {administrator ? (
                            <>
                                <Row label="Name" value={administrator.name} />
                                <Row label="Email" value={administrator.email} />
                                <Row label="Account Status" value={administrator.is_active ? <Badge variant="success">Active</Badge> : <Badge variant="secondary">Inactive</Badge>} />
                                <Row label="Created" value={formatDate(administrator.created_at)} />
                            </>
                        ) : (
                            <p className="text-graphite-400">
                                No Administrator account found for this tenant yet. This can happen for a tenant that
                                predates the Initial Administrator step (e.g. the seeded Default Tenant before its
                                first login was ever assigned a Super Admin account).
                            </p>
                        )}
                    </CardContent>
                </Card>
            </div>
        </PlatformLayout>
    );
}

function Row({ label, value }) {
    return (
        <div className="flex items-center justify-between gap-4 border-b border-graphite-100 pb-2 last:border-0 last:pb-0 dark:border-slate-800">
            <span className="text-graphite-500">{label}</span>
            <span className="font-medium text-graphite-800 dark:text-slate-100">{value}</span>
        </div>
    );
}
