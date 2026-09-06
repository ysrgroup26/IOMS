import { Head, Link, router, usePage } from '@inertiajs/react';
import { UserPlus, AlertTriangle, ExternalLink, PlayCircle } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/shared/PageHeader';
import { Card, CardContent } from '@/Components/ui/card';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { Button } from '@/Components/ui/button';

/**
 * v2.51.0 -- Master Admin > Registrations.
 *
 * The self-service onboarding pipeline. The row that matters most is a
 * registration marked PAID but not yet PROVISIONED: that is a customer
 * who has paid and has nothing, so it is called out at the top rather
 * than left for the operator to spot in a table.
 *
 * "Provision" is a recovery action, not an activation switch — the server
 * refuses it for anything that is not already paid, and it is idempotent.
 */
export default function Registrations({ registrations = [] }) {
    const { flash = {}, errors = {} } = usePage().props;

    const stuck = registrations.filter((r) => r.status === 'paid' && !r.provisioned_at);

    const fmt = (v) =>
        v ? new Date(v).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';

    const provision = (r) => {
        if (! confirm(`Re-run provisioning for ${r.reference}? This only works for a registration whose payment is already confirmed.`)) return;
        router.post(route('platform.registrations.provision', r.id), {}, { preserveScroll: true });
    };

    return (
        <PlatformLayout>
            <Head title="Registrations" />

            <PageHeader
                icon={UserPlus}
                title="Registrations"
                subtitle="Self-service signups in flight. A registration holds no application access until a verified payment provisions it."
            />

            {flash.success && (
                <div className="mb-4 rounded-lg border border-success/20 bg-success/[0.07] p-4 text-sm text-emerald-900">{flash.success}</div>
            )}
            {errors.registration && (
                <div className="mb-4 rounded-lg border border-danger/20 bg-danger/[0.06] p-4 text-sm text-red-900">{errors.registration}</div>
            )}

            {stuck.length > 0 && (
                <div className="mb-4 flex items-start gap-2.5 rounded-lg border border-warning/25 bg-warning/[0.07] p-4 text-sm leading-relaxed text-amber-900">
                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                    <span>
                        <strong>{stuck.length}</strong> registration{stuck.length === 1 ? ' has' : 's have'} a confirmed
                        payment but no workspace yet. Use Provision on those rows to complete setup.
                    </span>
                </div>
            )}

            <Card>
                <CardContent className="p-0">
                    {registrations.length === 0 ? (
                        <div className="p-6">
                            <EmptyState
                                icon={UserPlus}
                                title="No registrations yet"
                                description="Self-service signups from the public site appear here."
                            />
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Reference</TableHead>
                                        <TableHead>Company</TableHead>
                                        <TableHead>Contact</TableHead>
                                        <TableHead>Plan</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Verified</TableHead>
                                        <TableHead>Paid</TableHead>
                                        <TableHead>Tenant</TableHead>
                                        <TableHead className="text-right">Action</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {registrations.map((r) => (
                                        <TableRow key={r.id}>
                                            <TableCell className="whitespace-nowrap font-medium text-navy-900">
                                                {r.reference}
                                                <span className="block text-[11px] font-normal text-graphite-400">{fmt(r.created_at)}</span>
                                            </TableCell>
                                            <TableCell>
                                                <span className="font-medium text-navy-900">{r.company}</span>
                                                <span className="block text-[11px] text-graphite-400">{r.city || r.legal_name}</span>
                                            </TableCell>
                                            <TableCell>
                                                {r.contact_name}
                                                <span className="block text-[11px] text-graphite-400">{r.contact_email}</span>
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap">
                                                {r.plan || '—'}
                                                <span className="block text-[11px] text-graphite-400">
                                                    {r.billing_cycle === 'monthly' ? 'Monthly' : 'Annual'} · {r.amount}
                                                </span>
                                            </TableCell>
                                            <TableCell>
                                                <StatusBadge value={r.status} />
                                                {r.is_expired && <span className="block text-[11px] text-graphite-400">expired</span>}
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap text-graphite-500">{fmt(r.email_verified_at)}</TableCell>
                                            <TableCell className="whitespace-nowrap text-graphite-500">
                                                {fmt(r.paid_at)}
                                                {r.invoice_number && <span className="block text-[11px] text-graphite-400">{r.invoice_number}</span>}
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap">
                                                {r.tenant ? (
                                                    <Link href={route('platform.tenants.show', r.tenant.id)} className="inline-flex items-center gap-1 font-medium text-brand-700 hover:underline">
                                                        {r.tenant.name} <ExternalLink className="h-3 w-3" />
                                                    </Link>
                                                ) : (
                                                    <span className="text-graphite-400">—</span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {r.status === 'paid' && !r.provisioned_at && (
                                                    <Button size="sm" variant="outline" onClick={() => provision(r)}>
                                                        <PlayCircle className="h-4 w-4" /> Provision
                                                    </Button>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </CardContent>
            </Card>
        </PlatformLayout>
    );
}
