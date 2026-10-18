import { useState, useEffect } from 'react';
import { useForm, router, usePage } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Badge } from '@/Components/ui/badge';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter, DialogTrigger } from '@/Components/ui/dialog';
import EmployeeSelector from '@/Components/shared/EmployeeSelector';
import EmptyState from '@/Components/shared/EmptyState';
import { HardHat, Plus, KeyRound, Share2, Copy, Check, ShieldOff, ShieldCheck } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * v2.123.0 -- MY WORK ACCESS, AND WHY IT IS NOT THE USER FORM.
 *
 * Add Platform User INVENTS an identity: a name, an email, a password, a
 * role and a department. Add My Work User PICKS one that already exists in
 * the Employee Register and issues it an access code. Running both through
 * one form is what produced a second, diverging copy of a worker who was
 * already in the system, with nothing linking the two.
 *
 * So this card asks for exactly one thing -- WHO -- and then states what it
 * already knows about them. An administrator never retypes a name, a badge
 * number, a job title or a unit, because the register holds all four.
 *
 * THE ACCESS CODE IS SHOWN ONCE. It is generated server-side, stored only
 * as a hash, and flashed back for this one response. If it is lost before
 * it reaches the worker, the answer is Reset, not a lookup -- there is
 * nothing to look up. The Share panel therefore offers the link and the
 * instructions together, because the link alone is not access: it opens a
 * sign-in page that still demands the code (see ADR 062).
 */
export default function MyWorkAccessCard({ users = [], seats }) {
    const { flash } = usePage().props;
    const accounts = users.filter((u) => u.user_type === 'my_work');

    const [open, setOpen] = useState(false);
    const [issued, setIssued] = useState(null);

    /*
     * The server flashes the one plain-text copy of a new or reset code.
     * Picking it up here, rather than returning it from the request, means
     * a reset triggered from the table lands in the same panel as a
     * creation -- one place that knows how to present a code.
     */
    useEffect(() => {
        if (flash?.my_work_access) {
            setIssued(flash.my_work_access);
            setOpen(false);
        }
    }, [flash?.my_work_access]);

    return (
        <>
            <Card>
                <CardHeader className="flex flex-row items-start justify-between space-y-0 gap-3">
                    <div>
                        <CardTitle className="flex items-center gap-2"><HardHat className="h-4 w-4 text-brand-600" /> My Work Access</CardTitle>
                        <CardDescription>
                            Akun untuk pekerja lapangan. Pilih karyawan dari Employee Register, lalu bagikan kode aksesnya.
                        </CardDescription>
                    </div>
                    <Dialog open={open} onOpenChange={setOpen}>
                        <DialogTrigger asChild>
                            <Button size="sm"><Plus className="h-4 w-4" /> Add My Work User</Button>
                        </DialogTrigger>
                        <DialogContent>
                            <CreateMyWorkAccountForm
                                accounts={accounts}
                                onCancel={() => setOpen(false)}
                            />
                        </DialogContent>
                    </Dialog>
                </CardHeader>

                <CardContent>
                    <div className="mb-4 rounded-md border p-2 text-xs">
                        My Work Users: {seats?.used ?? accounts.length} / {seats?.limit ?? 'Unlimited'}
                        {' '}(included {seats?.included ?? 'Unlimited'} + {seats?.additional ?? 0} additional)
                    </div>

                    {accounts.length === 0 ? (
                        <EmptyState
                            icon={HardHat}
                            title="No My Work accounts yet"
                            description="Pilih karyawan dari Employee Register untuk memberi mereka akses My Work."
                        />
                    ) : (
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Employee</TableHead>
                                        <TableHead>Employee ID</TableHead>
                                        <TableHead>Position</TableHead>
                                        <TableHead>Operating Unit</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Last Sign In</TableHead>
                                        <TableHead />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {accounts.map((account) => (
                                        <AccountRow key={account.id} account={account} onShare={setIssued} />
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </CardContent>
            </Card>

            <AccessIssuedDialog issued={issued} onClose={() => setIssued(null)} />
        </>
    );
}

function AccountRow({ account, onShare }) {
    const employee = account.employee;

    function resetCode() {
        if (!confirm('Buat kode akses baru? Kode lama langsung berhenti berlaku.')) return;
        router.post(route('settings.my-work-users.access-code', account.id), {}, { preserveScroll: true });
    }

    function setActive(next) {
        router.put(route('settings.my-work-users.status', account.id), { is_active: next }, { preserveScroll: true });
    }

    return (
        <TableRow>
            {/* The register is read LIVE: a worker who was renamed or moved
                since the account was made is shown as they are now. */}
            <TableCell className="font-medium">
                {employee?.full_name ?? account.name}
                {!employee && (
                    <span className="block text-xs font-normal text-amber-600">
                        Karyawan tidak lagi ada di Employee Register.
                    </span>
                )}
            </TableCell>
            <TableCell className="tabular-nums">{employee?.employee_id ?? '-'}</TableCell>
            <TableCell>{employee?.position ?? '-'}</TableCell>
            <TableCell>{employee?.company ?? employee?.department ?? '-'}</TableCell>
            <TableCell>
                <Badge variant={account.is_active ? 'secondary' : 'outline'}>
                    {account.is_active ? 'Active' : 'Deactivated'}
                </Badge>
            </TableCell>
            <TableCell className="text-xs text-graphite-500">
                {account.last_login_at ? new Date(account.last_login_at).toLocaleString('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : 'Belum pernah'}
            </TableCell>
            <TableCell className="text-right">
                <div className="flex justify-end gap-1">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => onShare({
                            user_id: account.id,
                            employee_name: employee?.full_name ?? account.name,
                            employee_number: employee?.employee_id,
                        })}
                    >
                        <Share2 className="h-4 w-4" /> Share
                    </Button>
                    <Button variant="ghost" size="sm" onClick={resetCode}>
                        <KeyRound className="h-4 w-4" /> Reset code
                    </Button>
                    {account.is_active ? (
                        <Button variant="ghost" size="sm" className="text-red-600" onClick={() => setActive(false)}>
                            <ShieldOff className="h-4 w-4" /> Deactivate
                        </Button>
                    ) : (
                        <Button variant="ghost" size="sm" onClick={() => setActive(true)}>
                            <ShieldCheck className="h-4 w-4" /> Reactivate
                        </Button>
                    )}
                </div>
            </TableCell>
        </TableRow>
    );
}

/**
 * The form is ONE question. Everything under it is what IOMS already
 * knows about the answer, shown so the administrator can confirm they
 * picked the right person -- not so they can retype it.
 */
function CreateMyWorkAccountForm({ accounts, onCancel }) {
    const { data, setData, post, processing, errors, reset } = useForm({ employee_id: null, email: '' });
    const [employee, setEmployee] = useState(null);
    const [loading, setLoading] = useState(false);

    /*
     * EmployeeSelector emits the id as a STRING -- that is its documented
     * contract ("emits the same shape it was given", and it normalises to
     * String internally). The accounts come from Inertia as numbers, so
     * comparing them directly silently never matched and the duplicate
     * notice never appeared. Browser-verified: the warning stayed hidden
     * with the employee plainly selected. The server refused the duplicate
     * either way -- a unique index and a validation rule -- but an
     * administrator should be told before they press the button.
     */
    const selectedEmployeeId = data.employee_id ? Number(data.employee_id) : null;

    const existing = selectedEmployeeId
        ? accounts.find((a) => Number(a.employee_id) === selectedEmployeeId)
        : null;

    // The selector emits an id; the register holds the rest. Resolved
    // through the same lookup endpoint the selector itself uses, in its
    // hydration mode, so there is no second source for these facts.
    useEffect(() => {
        if (!data.employee_id) { setEmployee(null); return; }

        let cancelled = false;
        setLoading(true);

        fetch(route('employee-lookup.index') + '?ids[]=' + data.employee_id, { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((payload) => { if (!cancelled) setEmployee(payload?.data?.[0] ?? null); })
            .catch(() => { if (!cancelled) setEmployee(null); })
            .finally(() => { if (!cancelled) setLoading(false); });

        return () => { cancelled = true; };
    }, [data.employee_id]);

    function submit(e) {
        e.preventDefault();
        post(route('settings.my-work-users.store'), { onSuccess: () => reset() });
    }

    return (
        <>
            <DialogHeader><DialogTitle>Create My Work Access</DialogTitle></DialogHeader>

            <form onSubmit={submit} className="space-y-4">
                {Object.keys(errors).length > 0 && (
                    <div className="rounded-md border border-red-200 bg-red-50 p-2 text-xs text-red-700">
                        {Object.values(errors).map((msg, i) => <p key={i}>{msg}</p>)}
                    </div>
                )}

                <div className="space-y-1.5">
                    <Label>Employee</Label>
                    <EmployeeSelector
                        value={data.employee_id}
                        onChange={(id) => setData('employee_id', id)}
                        placeholder="Cari nama atau Employee ID..."
                    />
                    <p className="text-xs text-graphite-400">
                        Hanya karyawan yang sudah terdaftar di Employee Register. Proses ini tidak membuat data karyawan baru.
                    </p>
                </div>

                {data.employee_id && (
                    <div className="rounded-lg border border-graphite-200 bg-graphite-50/60 p-3 text-sm dark:border-slate-700 dark:bg-slate-900/40">
                        {loading && <p className="text-xs text-graphite-500">Memuat data karyawan...</p>}
                        {!loading && employee && (
                            <dl className="grid grid-cols-2 gap-x-4 gap-y-2">
                                <Fact label="Employee ID" value={employee.employee_id} />
                                <Fact label="Full Name" value={employee.full_name} />
                                <Fact label="Position" value={employee.position} />
                                <Fact label="Department" value={employee.department} />
                                <Fact label="Operating Unit" value={employee.company} />
                                <Fact label="Employee Status" value={employee.status} />
                            </dl>
                        )}
                    </div>
                )}

                {/* The duplicate rule, stated where it is discovered rather
                    than as a refusal after the fact. The server enforces it
                    too -- a unique index on users.employee_id. */}
                {existing && (
                    <div className="rounded-md border border-amber-200 bg-amber-50 p-3 text-xs leading-relaxed text-amber-900">
                        <p className="font-semibold">
                            Karyawan ini sudah memiliki akun My Work ({existing.is_active ? 'aktif' : 'nonaktif'}).
                        </p>
                        <p className="mt-1">
                            Gunakan tabel My Work Access untuk membagikan ulang link, mereset kode akses,
                            atau mengaktifkan kembali akunnya.
                        </p>
                    </div>
                )}

                <div className="space-y-1.5">
                    <Label htmlFor="mw-email">Email <span className="font-normal text-graphite-400">optional</span></Label>
                    <Input
                        id="mw-email"
                        type="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        placeholder="Kosongkan bila pekerja tidak memiliki email"
                    />
                    <p className="text-xs text-graphite-400">
                        Akun My Work masuk dengan Employee ID dan kode akses. Email hanya tambahan bila memang ada.
                    </p>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onCancel}>Cancel</Button>
                    <Button type="submit" disabled={processing || !data.employee_id || Boolean(existing)}>
                        Create Access
                    </Button>
                </DialogFooter>
            </form>
        </>
    );
}

function Fact({ label, value }) {
    return (
        <div>
            <dt className="text-[11px] uppercase tracking-wide text-graphite-400">{label}</dt>
            <dd className="font-medium text-graphite-900 dark:text-slate-100">{value || '-'}</dd>
        </div>
    );
}

/**
 * What the administrator does next: give the worker the link and the code.
 *
 * The link carries the Employee ID so it does not have to be typed, and
 * NOTHING else: a link is not access, and a code in a URL is a code in
 * every chat history, proxy log and screenshot it passes through.
 */
function AccessIssuedDialog({ issued, onClose }) {
    const [copied, setCopied] = useState(null);

    if (!issued) return null;

    const loginUrl = route('my-work.login') + (issued.employee_number ? '?employee=' + encodeURIComponent(issued.employee_number) : '');
    const instructions = [
        `Akses My Work IOMS untuk ${issued.employee_name}`,
        issued.employee_number ? `Employee ID: ${issued.employee_number}` : null,
        issued.code ? `Kode akses: ${issued.code}` : null,
        `Buka: ${loginUrl}`,
        'Masukkan Employee ID dan kode akses pada halaman tersebut.',
    ].filter(Boolean).join('\n');

    async function copy(text, which) {
        try {
            await navigator.clipboard.writeText(text);
            setCopied(which);
            setTimeout(() => setCopied(null), 2000);
        } catch {
            // A clipboard an administrator's browser refuses is not an
            // error worth a modal: the text is on screen and selectable.
            setCopied(null);
        }
    }

    async function share() {
        if (navigator.share) {
            try {
                await navigator.share({ title: 'IOMS My Work', text: instructions, url: loginUrl });
                return;
            } catch {
                // Cancelled or unavailable -- fall through to copying,
                // which is the same outcome by another route.
            }
        }
        copy(instructions, 'instructions');
    }

    return (
        <Dialog open onOpenChange={(next) => { if (!next) onClose(); }}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{issued.reset ? 'New access code' : 'My Work access created'}</DialogTitle>
                </DialogHeader>

                <div className="space-y-4">
                    <div className="rounded-lg border border-graphite-200 p-3">
                        <p className="text-[11px] uppercase tracking-wide text-graphite-400">Employee</p>
                        <p className="font-semibold text-graphite-900">{issued.employee_name}</p>
                        {issued.employee_number && (
                            <p className="text-xs text-graphite-500 tabular-nums">{issued.employee_number}</p>
                        )}
                    </div>

                    {issued.code ? (
                        <div className="rounded-lg border border-brand-200 bg-brand-50/60 p-3">
                            <p className="text-[11px] uppercase tracking-wide text-brand-700">Access Code</p>
                            <p className="mt-0.5 font-mono text-2xl font-semibold tracking-[0.2em] text-navy-900">{issued.code}</p>
                            <p className="mt-1.5 text-xs leading-relaxed text-graphite-600">
                                Kode ini hanya ditampilkan sekali dan tidak disimpan dalam bentuk aslinya.
                                Bila hilang, buat kode baru lewat Reset code.
                            </p>
                            <Button variant="outline" size="sm" className="mt-2" onClick={() => copy(issued.code, 'code')}>
                                {copied === 'code' ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />} Copy code
                            </Button>
                        </div>
                    ) : (
                        <div className="rounded-md border border-graphite-200 bg-graphite-50 p-3 text-xs leading-relaxed text-graphite-600">
                            Kode akses tidak ditampilkan ulang. Gunakan Reset code bila pekerja ini memerlukan kode baru.
                        </div>
                    )}

                    <div className="space-y-1.5">
                        <Label>Access Link</Label>
                        <Input readOnly value={loginUrl} onFocus={(e) => e.target.select()} className="text-xs" />
                        <p className="text-xs text-graphite-400">
                            Link ini membuka halaman masuk My Work dan berisi Employee ID saja.
                            Tanpa kode akses, link ini tidak memberi akses apa pun.
                        </p>
                    </div>

                    <div className={cn('flex flex-wrap gap-2')}>
                        <Button onClick={share}><Share2 className="h-4 w-4" /> Share Access Link</Button>
                        <Button variant="outline" onClick={() => copy(loginUrl, 'link')}>
                            {copied === 'link' ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />} Copy link
                        </Button>
                        <Button variant="outline" onClick={() => copy(instructions, 'instructions')}>
                            {copied === 'instructions' ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />} Copy instructions
                        </Button>
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>Done</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
