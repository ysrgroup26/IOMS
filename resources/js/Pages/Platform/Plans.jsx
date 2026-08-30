import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Checkbox } from '@/Components/ui/checkbox';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import EmptyState from '@/Components/shared/EmptyState';
import { Tag, Plus, Pencil } from 'lucide-react';

/**
 * v1.11.0 (SaaS Finalization Pass, Part 9/18). `Package` (Milestone 2)
 * already existed as the Plan/Edition model -- this is its first actual
 * management UI. Previously only ever read via `Package::active()` for a
 * dropdown inside Tenant create/edit; a Platform Admin had no way to
 * create a new plan or edit an existing one's price/limits at all.
 */
export default function PlatformPlans({ plans }) {
    const [dialogPlan, setDialogPlan] = useState(null); // null = closed, {} = create, {...} = edit

    return (
        <PlatformLayout>
            <Head title="Plans" />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-[22px] font-semibold tracking-tight text-graphite-900">Plans / Editions</h1>
                    <p className="mt-1 text-sm text-graphite-500">The pricing/feature tiers tenants can be subscribed to.</p>
                </div>
                <Button size="sm" onClick={() => setDialogPlan({})}><Plus className="h-4 w-4" /> New Plan</Button>
            </div>

            <Card>
                <CardHeader><CardTitle className="flex items-center gap-2"><Tag className="h-4 w-4" /> Plan Catalog</CardTitle><CardDescription>Module/feature entitlements per plan are managed via Tenant Grants (Platform → Tenants → Manage Grants) for the tenants subscribed to it.</CardDescription></CardHeader>
                <CardContent className="p-0">
                    {plans.length === 0 ? (
                        <EmptyState icon={Tag} title="No plans defined yet" />
                    ) : (
                        <Table>
                            <TableHeader><TableRow><TableHead>Name</TableHead><TableHead>Slug</TableHead><TableHead>Monthly</TableHead><TableHead>Yearly</TableHead><TableHead>Trial</TableHead><TableHead>Max Users</TableHead><TableHead>Max Companies</TableHead><TableHead>Status</TableHead><TableHead /></TableRow></TableHeader>
                            <TableBody>
                                {plans.map((p) => (
                                    <TableRow key={p.id}>
                                        <TableCell className="font-medium">{p.name}</TableCell>
                                        <TableCell><code className="text-xs">{p.slug}</code></TableCell>
                                        <TableCell>{p.is_custom ? 'Custom' : (p.price_monthly ? `${p.currency} ${Number(p.price_monthly).toLocaleString()}` : '—')}</TableCell>
                                        <TableCell>{p.is_custom ? 'Custom' : (p.price_yearly ? `${p.currency} ${Number(p.price_yearly).toLocaleString()}` : '—')}</TableCell>
                                        <TableCell>{p.trial_days ? `${p.trial_days}d` : '—'}</TableCell>
                                        <TableCell>{p.max_users ?? 'Unlimited'}</TableCell>
                                        <TableCell>{p.max_companies ?? 'Unlimited'}</TableCell>
                                        <TableCell className="space-x-1">
                                            <Badge variant={p.is_active ? 'success' : 'secondary'}>{p.is_active ? 'Active' : 'Inactive'}</Badge>
                                            {!p.is_public && <Badge variant="outline">Internal</Badge>}
                                        </TableCell>
                                        <TableCell><Button variant="ghost" size="icon" onClick={() => setDialogPlan(p)}><Pencil className="h-4 w-4" /></Button></TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            {dialogPlan && <PlanDialog plan={dialogPlan.id ? dialogPlan : null} onClose={() => setDialogPlan(null)} />}
        </PlatformLayout>
    );
}

function PlanDialog({ plan, onClose }) {
    const editing = !!plan;
    const { data, setData, post, put, processing, errors } = useForm({
        name: plan?.name || '',
        slug: plan?.slug || '',
        description: plan?.description || '',
        price_monthly: plan?.price_monthly ?? '',
        price_yearly: plan?.price_yearly ?? '',
        currency: plan?.currency ?? 'IDR',
        trial_days: plan?.trial_days ?? '',
        max_users: plan?.max_users ?? '',
        max_companies: plan?.max_companies ?? '',
        is_active: plan?.is_active ?? true,
        is_public: plan?.is_public ?? true,
        is_custom: plan?.is_custom ?? false,
        sort_order: plan?.sort_order ?? 0,
    });

    function submit(e) {
        e.preventDefault();
        if (editing) {
            put(route('platform.plans.update', plan.id), { preserveScroll: true, onSuccess: onClose });
        } else {
            post(route('platform.plans.store'), { preserveScroll: true, onSuccess: onClose });
        }
    }

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <DialogHeader><DialogTitle>{editing ? `Edit ${plan.name}` : 'New Plan'}</DialogTitle></DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1.5"><Label>Name</Label><Input value={data.name} onChange={(e) => setData('name', e.target.value)} /></div>
                        <div className="space-y-1.5"><Label>Slug</Label><Input value={data.slug} onChange={(e) => setData('slug', e.target.value)} /></div>
                    </div>
                    <div className="space-y-1.5"><Label>Description</Label><Textarea rows={2} value={data.description} onChange={(e) => setData('description', e.target.value)} /></div>
                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1.5"><Label>Price / Month</Label><Input type="number" min="0" step="0.01" value={data.price_monthly} onChange={(e) => setData('price_monthly', e.target.value)} disabled={data.is_custom} /></div>
                        <div className="space-y-1.5"><Label>Price / Year</Label><Input type="number" min="0" step="0.01" value={data.price_yearly} onChange={(e) => setData('price_yearly', e.target.value)} disabled={data.is_custom} /></div>
                        <div className="space-y-1.5"><Label>Currency</Label><Input value={data.currency} onChange={(e) => setData('currency', e.target.value.toUpperCase())} maxLength={3} /></div>
                        <div className="space-y-1.5"><Label>Trial Days (blank = no trial)</Label><Input type="number" min="0" value={data.trial_days} onChange={(e) => setData('trial_days', e.target.value)} /></div>
                        <div className="space-y-1.5"><Label>Max Users (blank = unlimited)</Label><Input type="number" min="1" value={data.max_users} onChange={(e) => setData('max_users', e.target.value)} /></div>
                        <div className="space-y-1.5"><Label>Max Companies (blank = unlimited)</Label><Input type="number" min="1" value={data.max_companies} onChange={(e) => setData('max_companies', e.target.value)} /></div>
                    </div>
                    <div className="flex flex-wrap items-center gap-4">
                        <div className="flex items-center gap-2"><Checkbox checked={data.is_active} onCheckedChange={(v) => setData('is_active', Boolean(v))} /><Label className="!mt-0">Active</Label></div>
                        <div className="flex items-center gap-2"><Checkbox checked={data.is_public} onCheckedChange={(v) => setData('is_public', Boolean(v))} /><Label className="!mt-0">Show on Plans page</Label></div>
                        <div className="flex items-center gap-2"><Checkbox checked={data.is_custom} onCheckedChange={(v) => setData('is_custom', Boolean(v))} /><Label className="!mt-0">Custom pricing ("Contact Us")</Label></div>
                    </div>
                    {Object.keys(errors).length > 0 && (
                        <div className="rounded-md border border-red-200 bg-red-50 p-2 text-xs text-red-700">{Object.values(errors).map((m, i) => <p key={i}>{m}</p>)}</div>
                    )}
                    <DialogFooter><Button type="button" variant="outline" onClick={onClose}>Cancel</Button><Button type="submit" disabled={processing}>Save</Button></DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
