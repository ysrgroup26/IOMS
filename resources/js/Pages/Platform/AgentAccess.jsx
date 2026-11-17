import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Checkbox } from '@/Components/ui/checkbox';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import EmptyState from '@/Components/shared/EmptyState';
import PageHeader from '@/Components/shared/PageHeader';
import { Bot, Copy, KeyRound, Trash2 } from 'lucide-react';

/**
 * ADR 086 -- Master Admin > Akses Agent.
 *
 * Issues the read-only tokens TICorp's AI Workforce uses for /api/agent/v1, and
 * revokes them. A new token's plaintext arrives once, through the flash of the
 * redirect that created it, and is never shown again.
 */
const fmt = (v) => (v ? new Date(v).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) : '-');

export default function PlatformAgentAccess({ tokens, owners, scopes, endpoint, new_token }) {
    const [copied, setCopied] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        name: 'TICorp',
        owner_id: owners[0]?.id ?? '',
        scopes: Object.keys(scopes),
        expires_in_days: '',
    });

    function toggleScope(key, on) {
        setData('scopes', on ? [...new Set([...data.scopes, key])] : data.scopes.filter((s) => s !== key));
    }

    function submit(e) {
        e.preventDefault();
        post(route('platform.agent-access.store'), { preserveScroll: true, onSuccess: () => reset('expires_in_days') });
    }

    function revoke(t) {
        if (window.confirm(`Cabut token "${t.name}"? Agent yang memakainya langsung kehilangan akses.`)) {
            router.delete(route('platform.agent-access.destroy', t.id), { preserveScroll: true });
        }
    }

    async function copy(text) {
        try { await navigator.clipboard.writeText(text); setCopied(true); } catch { setCopied(false); }
    }

    return (
        <PlatformLayout>
            <Head title="Akses Agent" />
            <PageHeader title="Akses Agent" subtitle="Token baca-saja untuk AI Workforce TICorp. Agent membaca data Master Admin lewat API; tidak ada yang bisa diubah dengan token ini." />

            {new_token && (
                <Card className="mb-4 border-amber-300">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2"><KeyRound className="h-4 w-4" /> Token baru: {new_token.name}</CardTitle>
                        <CardDescription>Salin sekarang dan tempel di TICorp (Agent Office &rarr; Akun produk agent). Token ini tidak akan ditampilkan lagi.</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        <code className="type-code block break-all rounded-md border bg-graphite-50 p-3 text-xs">{new_token.plaintext}</code>
                        <Button size="sm" variant="outline" onClick={() => copy(new_token.plaintext)}><Copy className="h-4 w-4" /> {copied ? 'Tersalin' : 'Salin token'}</Button>
                    </CardContent>
                </Card>
            )}

            <div className="grid gap-4 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader><CardTitle className="flex items-center gap-2"><Bot className="h-4 w-4" /> Token aktif</CardTitle><CardDescription>Endpoint: <code className="type-code text-xs">{endpoint}</code></CardDescription></CardHeader>
                    <CardContent className="p-0">
                        {tokens.length === 0 ? (
                            <EmptyState icon={Bot} title="Belum ada token agent" />
                        ) : (
                            <Table>
                                <TableHeader><TableRow><TableHead>Nama</TableHead><TableHead>Akun pemilik</TableHead><TableHead>Cakupan</TableHead><TableHead>Terakhir dipakai</TableHead><TableHead>Kedaluwarsa</TableHead><TableHead /></TableRow></TableHeader>
                                <TableBody>
                                    {tokens.map((t) => (
                                        <TableRow key={t.id}>
                                            <TableCell className="font-medium">{t.name}</TableCell>
                                            <TableCell>{t.owner ? `${t.owner.name}` : '-'}</TableCell>
                                            <TableCell className="space-x-1">{t.scopes.map((s) => <Badge key={s} variant="secondary">{s}</Badge>)}</TableCell>
                                            <TableCell>{fmt(t.last_used_at)}</TableCell>
                                            <TableCell>{t.expires_at ? fmt(t.expires_at) : 'Tidak'}</TableCell>
                                            <TableCell><Button variant="ghost" size="icon" aria-label={`Cabut ${t.name}`} onClick={() => revoke(t)}><Trash2 className="h-4 w-4" /></Button></TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle>Terbitkan token</CardTitle><CardDescription>Pemilik sebaiknya akun khusus AI Workforce, bukan akun pribadi.</CardDescription></CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="space-y-3">
                            <div className="space-y-1.5"><Label htmlFor="agent-name">Nama</Label><Input id="agent-name" value={data.name} onChange={(e) => setData('name', e.target.value)} /></div>
                            <div className="space-y-1.5">
                                <Label htmlFor="agent-owner">Akun pemilik</Label>
                                <select id="agent-owner" className="h-9 w-full rounded-md border border-graphite-300 bg-white px-2 text-sm" value={data.owner_id} onChange={(e) => setData('owner_id', Number(e.target.value))}>
                                    {owners.map((o) => <option key={o.id} value={o.id}>{o.name} ({o.email})</option>)}
                                </select>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Cakupan</Label>
                                {Object.entries(scopes).map(([key, label]) => (
                                    <div key={key} className="flex items-center gap-2"><Checkbox checked={data.scopes.includes(key)} onCheckedChange={(v) => toggleScope(key, Boolean(v))} /><span className="text-sm"><strong>{key}</strong> · {label}</span></div>
                                ))}
                            </div>
                            <div className="space-y-1.5"><Label htmlFor="agent-exp">Kedaluwarsa (hari, kosong = tidak)</Label><Input id="agent-exp" type="number" min="1" max="730" value={data.expires_in_days} onChange={(e) => setData('expires_in_days', e.target.value)} /></div>
                            {Object.keys(errors).length > 0 && (
                                <div className="rounded-md border border-red-200 bg-red-50 p-2 text-xs text-red-700">{Object.values(errors).map((m, i) => <p key={i}>{m}</p>)}</div>
                            )}
                            <Button type="submit" disabled={processing}>Terbitkan token</Button>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </PlatformLayout>
    );
}
