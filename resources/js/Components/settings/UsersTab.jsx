/* v2.141.0 -- split out of Pages/Settings/Index.jsx (M0, Settings architecture). Behaviour unchanged. */
import { useForm, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { PasswordInput } from '@/Components/ui/password-input';
import { Label } from '@/Components/ui/label';
import { Badge } from '@/Components/ui/badge';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Checkbox } from '@/Components/ui/checkbox';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter, DialogTrigger } from '@/Components/ui/dialog';
import { Plus, Trash2, Pencil, Loader2, Search, Info } from 'lucide-react';
import MyWorkAccessCard from '@/Components/shared/MyWorkAccessCard';
import { ROLE_LABELS, BUILT_IN_ROLES, departmentLabel } from './shared';

function DepartmentField({ value, onChange, options = [] }) {
    return (
        <div className="space-y-1.5">
            <Label>Department Restriction</Label>
            <Select value={value || 'none'} onValueChange={(v) => onChange(v === 'none' ? '' : v)}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                    <SelectItem value="none">None -- Administrator (sees every department)</SelectItem>
                    {options.map((d) => <SelectItem key={d.key} value={d.key}>{d.label}</SelectItem>)}
                </SelectContent>
            </Select>
            <p className="text-xs text-graphite-400">
                Restricts this account to only the selected department's sidebar and routes -- enforced by the backend, not just hidden from view.
                Leave as "None" for a full Administrator (matches every account's behavior today).
            </p>
        </div>
    );
}


/**
 * v2.19.0 (PTW Access Management Correction pass, Part 1). Splits what
 * used to be one `<Card>` (User Management table with Field & PTW Access
 * bolted onto its bottom as a `border-t` sub-section) into two
 * INDEPENDENT cards -- the User Management card (create/edit/delete/role
 * changes) now renders ONLY for `canManageUsers` (Super Admin), while
 * the Field & PTW Access card renders for `canManageUsers ||
 * canPtwAccess` (Super Admin OR HSE). Previously this whole tab was
 * Super-Admin-only end to end, so there was no need for this split; now
 * that HSE can reach the Users tab specifically to manage PTW Access,
 * bolting it onto a Card that only Super Admin should see the CRUD
 * actions of would either hide PTW Access from HSE entirely (defeating
 * the point) or expose user create/edit/delete/role-change to HSE
 * (explicitly forbidden by this pass's own directive). Splitting the
 * cards is the smallest correct fix -- same components, same data, no
 * new page.
 */
/**
 * v2.54.0 -- `canFieldAccess` used to be READ HERE WITHOUT BEING A PROP.
 * It is declared inside SettingsIndex, and this is a separate
 * module-scope function, so the reference threw a ReferenceError the
 * moment the Users tab mounted (Radix only mounts the active tab's
 * content, which is why the rest of Settings looked fine). Threaded
 * through as a prop, like every other capability flag on this page.
 */
/**
 * v2.100.0 -- THE TWO NAMES A PERSON HAS, kept apart on screen the way
 * they are kept apart in the table.
 *
 * "Name" is the registered identity: it arrives from the registration's
 * contact name, it belongs beside the email on an invoice and in support,
 * and it should be slow to change. "Display Name" is what colleagues see
 * in the sidebar, approval queues and timelines. Leaving it blank shows
 * the registered name, which is what every account did before this
 * existed -- so this field adds a choice and takes none away.
 */
function DisplayNameField({ value, onChange, placeholder }) {
    return (
        <div className="space-y-1.5">
            <Label>Display Name</Label>
            <Input value={value} onChange={(e) => onChange(e.target.value)} placeholder={placeholder} />
            <p className="text-xs text-graphite-400">
                Nama yang dilihat rekan kerja di sidebar, daftar persetujuan, dan riwayat aktivitas.
                Kosongkan untuk memakai nama terdaftar.
            </p>
        </div>
    );
}


export default function UsersTab({ users, roles, ptwAccess, userSeats, canManageUsers, canPtwAccess, canFieldAccess, companies, assignableDepartments = [] }) {
    return (
        <div className="space-y-4">
            {canManageUsers && <UserManagementCard users={users} roles={roles} companies={companies} userSeats={userSeats} assignableDepartments={assignableDepartments} />}
            {/* v2.123.0 -- the second creation flow, as its own card.
                A My Work account is picked from the Employee Register and
                issued an access code; it has no role and no department to
                set, so it does not belong in the form above. Same gate,
                same quota -- see ADR 061 and the card's own note. */}
            {canManageUsers && <MyWorkAccessCard users={users} seats={userSeats?.my_work} />}
            {(canManageUsers || canPtwAccess) && <FieldPtwAccessCard users={users} ptwAccess={ptwAccess} canFieldAccess={canFieldAccess} canManageUsers={canManageUsers} />}
        </div>
    );
}


function UserManagementCard({ users, roles, companies, userSeats, assignableDepartments = [] }) {
    const [open, setOpen] = useState(false);
    const [editingRolesFor, setEditingRolesFor] = useState(null);
    const [editingUser, setEditingUser] = useState(null);
    const [editingUnitsFor, setEditingUnitsFor] = useState(null);
    const multiUnit = (companies ?? []).length > 1;
    /*
     * v2.122.0 -- the new account's HOME operating unit.
     *
     * This form never sent one, so every account it created was stored
     * with company_id NULL. The administrator's own unit is the honest
     * default (and the only possible answer on a single-unit tenant);
     * on a tenant with several units the choice is shown, because
     * guessing one assigns somebody to a unit nobody picked. This is
     * the account's HOME unit -- which units it may additionally SEE is
     * the separate "Operating Units" grant edited from the table below.
     */
    const { auth } = usePage().props;
    const defaultCompanyId = auth?.user?.company_id ? String(auth.user.company_id) : '';
    const { data, setData, post, processing, reset, errors } = useForm({ name: '', display_name: '', email: '', password: '', role: 'hrd', department_key: '', user_type: 'full', company_id: defaultCompanyId });
    const customRoles = (roles ?? []).filter((r) => !BUILT_IN_ROLES.includes(r.name));
    const platformUsers = (users ?? []).filter((u) => u.user_type !== 'my_work');

    function submit(e) {
        e.preventDefault();
        post(route('settings.users.store'), { onSuccess: () => { reset(); setOpen(false); } });
    }

    function destroy(id) {
        if (confirm('Hapus pengguna ini?')) router.delete(route('settings.users.destroy', id));
    }

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0">
                <div>
                    <CardTitle>Platform Users</CardTitle>
                    <CardDescription>
                        Akun untuk dashboard IOMS: Administrator, HSE, HRD, Manager atau Warehouse.
                        Akun pekerja lapangan diatur di My Work Access di bawah.
                    </CardDescription>
                </div>
                <Dialog open={open} onOpenChange={setOpen}>
                    <DialogTrigger asChild><Button size="sm"><Plus className="h-4 w-4" /> Add Platform User</Button></DialogTrigger>
                    <DialogContent>
                        <DialogHeader><DialogTitle>Create Platform User</DialogTitle></DialogHeader>
                        <form onSubmit={submit} className="space-y-3">
                            {/* v1.10.8: was previously silent for any field
                                other than email/password -- a role or
                                department_key validation failure gave no
                                visible feedback at all, easy to mistake for
                                "saved successfully, just didn't apply." */}
                            {Object.keys(errors).length > 0 && (
                                <div className="rounded-md border border-red-200 bg-red-50 p-2 text-xs text-red-700">
                                    {Object.values(errors).map((msg, i) => <p key={i}>{msg}</p>)}
                                </div>
                            )}
                            <div className="space-y-1.5"><Label>Name</Label><Input value={data.name} onChange={(e) => setData('name', e.target.value)} /></div>
                            <DisplayNameField value={data.display_name} onChange={(v) => setData('display_name', v)} placeholder={data.name || 'Sama dengan nama terdaftar'} />
                            <div className="space-y-1.5"><Label>Email</Label><Input type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                                {errors.email && <p className="text-xs text-red-600">{errors.email}</p>}
                            </div>
                            <div className="space-y-1.5"><Label>Password</Label><PasswordInput value={data.password} onChange={(e) => setData('password', e.target.value)} />
                                {errors.password && <p className="text-xs text-red-600">{errors.password}</p>}
                            </div>
                            <div className="space-y-1.5">
                                <Label>Account Class</Label>
                                <Select value={data.user_type} onValueChange={(v) => setData('user_type', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="full">Full User</SelectItem>
                                        <SelectItem value="my_work">My Work User</SelectItem>
                                    </SelectContent>
                                </Select>
                                <p className="text-xs text-graphite-400">
                                    Kelas akun menentukan kuota yang dipakai. Untuk pekerja lapangan,
                                    gunakan Add My Work User agar akunnya terhubung ke Employee Register
                                    dan bisa masuk dengan Employee ID.
                                </p>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Role</Label>
                                <Select value={data.role} onValueChange={(v) => setData('role', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="super_admin">Administrator</SelectItem>
                                        <SelectItem value="hse">HSE</SelectItem>
                                        <SelectItem value="hrd">HRD</SelectItem>
                                        <SelectItem value="manager">Manager</SelectItem>
                                        <SelectItem value="warehouse">Warehouse</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <DepartmentField value={data.department_key} onChange={(v) => setData('department_key', v)} options={assignableDepartments} />
                            {multiUnit && (
                                <div className="space-y-1.5">
                                    <Label>Operating Unit</Label>
                                    <Select value={data.company_id} onValueChange={(v) => setData('company_id', v)}>
                                        <SelectTrigger><SelectValue placeholder="Pilih unit operasi" /></SelectTrigger>
                                        <SelectContent>
                                            {(companies ?? []).map((c) => (
                                                <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <p className="text-xs text-graphite-400">Unit tempat akun ini bekerja. Akses ke unit lain diatur terpisah lewat kolom Operating Units di tabel.</p>
                                    {errors.company_id && <p className="text-xs text-red-600">{errors.company_id}</p>}
                                </div>
                            )}
                            <DialogFooter><Button type="submit" disabled={processing}>Create</Button></DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </CardHeader>
            <CardContent>
                <div className="mb-4 grid gap-2 text-xs sm:grid-cols-2">
                    <div className="rounded-md border p-2">Full Users: {userSeats?.full?.used ?? 0} / {userSeats?.full?.limit ?? 'Unlimited'} (included {userSeats?.full?.included ?? 'Unlimited'} + {userSeats?.full?.additional ?? 0} additional)</div>
                    <div className="rounded-md border p-2">My Work Users: {userSeats?.my_work?.used ?? 0} / {userSeats?.my_work?.limit ?? 'Unlimited'} (included {userSeats?.my_work?.included ?? 'Unlimited'} + {userSeats?.my_work?.additional ?? 0} additional)</div>
                </div>
                <Table>
                    <TableHeader><TableRow><TableHead>Name</TableHead><TableHead>Email</TableHead><TableHead>Account Class</TableHead><TableHead>Role</TableHead><TableHead>Department</TableHead>{multiUnit && <TableHead>Operating Units</TableHead>}<TableHead>Custom Roles</TableHead><TableHead>Status</TableHead><TableHead /></TableRow></TableHeader>
                    <TableBody>
                        {/* v2.123.0: My Work accounts are listed in their own
                            card below, where the actions that apply to them
                            (share, reset code, deactivate) actually live.
                            Every one of them appears there, including an
                            older one created through this form and therefore
                            carrying no link to the register. */}
                        {platformUsers.map((u) => {
                            const assignedCustom = customRoles.filter((r) => (u.role_ids ?? []).includes(r.id));
                            const deptLabel = u.department_key ? departmentLabel(u.department_key) : undefined;
                            return (
                                <TableRow key={u.id}>
                                    <TableCell className="font-medium">
                                        {u.display ?? u.name}
                                        {u.display_name && u.display_name !== u.name && (
                                            <span className="block text-xs font-normal text-graphite-400">{u.name}</span>
                                        )}
                                    </TableCell>
                                    <TableCell>{u.email}</TableCell>
                                    <TableCell><Badge variant={u.user_type === 'my_work' ? 'outline' : 'secondary'}>{u.user_type === 'my_work' ? 'My Work User' : 'Full User'}</Badge></TableCell>
                                    <TableCell><Badge variant={u.role === 'super_admin' ? 'default' : 'secondary'}>{ROLE_LABELS[u.role] ?? u.role}</Badge></TableCell>
                                    <TableCell>
                                        {deptLabel ? <Badge variant="outline">{deptLabel}</Badge> : <span className="text-xs text-graphite-400">Administrator (all)</span>}
                                    </TableCell>
                                    {/* v2.54.0: only shown to an organization that actually
                                        runs more than one operating unit -- a single-unit
                                        customer has nothing to decide here. An empty grant
                                        list means every unit, never none. */}
                                    {multiUnit && (
                                        <TableCell>
                                            <div className="flex flex-wrap items-center gap-1">
                                                {(u.company_ids ?? []).length === 0
                                                    ? <span className="text-xs text-graphite-400">All units</span>
                                                    : (companies ?? []).filter((c) => (u.company_ids ?? []).includes(c.id))
                                                        .map((c) => <Badge key={c.id} variant="outline">{c.code || c.name}</Badge>)}
                                                <button type="button" onClick={() => setEditingUnitsFor(u)} className="text-xs text-brand-600 hover:underline">
                                                    Edit
                                                </button>
                                            </div>
                                        </TableCell>
                                    )}
                                    <TableCell>
                                        <div className="flex flex-wrap items-center gap-1">
                                            {assignedCustom.map((r) => <Badge key={r.id} variant="outline">{r.name}</Badge>)}
                                            {customRoles.length > 0 && (
                                                <button type="button" onClick={() => setEditingRolesFor(u)} className="text-xs text-brand-600 hover:underline">
                                                    Edit
                                                </button>
                                            )}
                                        </div>
                                    </TableCell>
                                    <TableCell><Badge variant={u.is_active ? 'success' : 'secondary'}>{u.is_active ? 'Active' : 'Inactive'}</Badge></TableCell>
                                    <TableCell className="flex items-center gap-1">
                                        <Button variant="ghost" size="icon" onClick={() => setEditingUser(u)}><Pencil className="h-4 w-4" /></Button>
                                        <Button variant="ghost" size="icon" onClick={() => destroy(u.id)}><Trash2 className="h-4 w-4 text-red-500" /></Button>
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
            </CardContent>
            {editingRolesFor && (
                <UserRolesDialog user={editingRolesFor} customRoles={customRoles} onClose={() => setEditingRolesFor(null)} />
            )}
            {editingUser && (
                <EditUserDialog user={editingUser} onClose={() => setEditingUser(null)} assignableDepartments={assignableDepartments} />
            )}
            {editingUnitsFor && (
                <UserOperatingUnitsDialog user={editingUnitsFor} companies={companies ?? []} onClose={() => setEditingUnitsFor(null)} />
            )}
        </Card>
    );
}


/**
 * v2.54.0 -- which operating units an account may reach.
 *
 * The server is the authority: it validates every id against this
 * organization, and it is the server that decides an all-units selection
 * is stored as "no restriction". This dialog only expresses intent.
 */
function UserOperatingUnitsDialog({ user, companies, onClose }) {
    const { data, setData, put, processing } = useForm({ company_ids: user.company_ids ?? [] });
    const unrestricted = data.company_ids.length === 0;

    function toggle(id) {
        setData('company_ids', data.company_ids.includes(id)
            ? data.company_ids.filter((x) => x !== id)
            : [...data.company_ids, id]);
    }

    function submit(e) {
        e.preventDefault();
        put(route('settings.users.companies', user.id), { onSuccess: onClose });
    }

    return (
        <Dialog open onOpenChange={onClose}>
            <DialogContent>
                <DialogHeader><DialogTitle>Operating Unit Access: {user.name}</DialogTitle></DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <p className="text-xs leading-relaxed text-graphite-500">
                        Pilih unit yang boleh diakses akun ini. Jika tidak ada satu pun yang dipilih, akun
                        dapat mengakses seluruh Operating Unit dalam organisasi. Pembatasan bersifat opsional
                        dan hanya aktif saat Anda memilih unit tertentu.
                    </p>
                    <div className="space-y-2">
                        {companies.map((c) => (
                            <label key={c.id} className="flex items-center gap-2 text-sm">
                                <Checkbox checked={data.company_ids.includes(c.id)} onCheckedChange={() => toggle(c.id)} />
                                <span>{c.name}{c.code ? <span className="ml-1 text-xs text-graphite-400">({c.code})</span> : null}</span>
                            </label>
                        ))}
                    </div>
                    {unrestricted && (
                        <p className="rounded-md border border-graphite-200 bg-graphite-50 p-2 text-xs text-graphite-600">
                            Saat ini: akses ke seluruh Operating Unit.
                        </p>
                    )}
                    <DialogFooter><Button type="submit" disabled={processing}>Save</Button></DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}


/**
 * v2.17.0 (PTW Field Workflow Foundation + Controlled PTW Access, Part
 * 3/4/7). "PTW Access" -- deliberately a SEPARATE card/concern from
 * ordinary User Management above (different endpoint, different
 * business rule: quota-checked, not just a profile edit). Reuses the
 * exact same search-input pattern already established elsewhere in this
 * codebase (e.g. Incidents/Index.jsx's filter bar) rather than inventing
 * a new one. Client-side filtering only (this tenant's own user list is
 * already small enough to have been sent whole by SettingsController::
 * index() -- no new endpoint needed for search).
 */
/**
 * v2.71.0 -- "WHERE DO I MANAGE FIELD PTW USERS?" NOW HAS AN ANSWER ON
 * THE PAGE.
 *
 * Reported as not being able to tell where the email/password/access for
 * field PTW users is managed. Auditing it, the capability was real but
 * split across two roles with nothing saying so:
 *
 *   creating the account, email, password   Super Admin  (User Management)
 *   PTW Access + field workspace            Super Admin OR HSE  (this card)
 *
 * An HSE administrator opening this tab saw only this card, could grant
 * PTW Access to people who already had accounts, and had no indication
 * that new accounts come from somewhere else -- so the natural conclusion
 * was that the feature was missing.
 *
 * The split itself is correct and is NOT being removed: issuing
 * credentials is an administrative act, and widening it to HSE would be a
 * real authorization change for a discoverability problem. What was
 * missing is the product saying so, which is what the note below does.
 */
function FieldPtwAccessCard({ users, ptwAccess, canFieldAccess, canManageUsers }) {
    const [search, setSearch] = useState('');
    const used = ptwAccess?.used ?? 0;
    const quota = ptwAccess?.quota ?? null;

    const filtered = users.filter((u) => {
        const q = search.trim().toLowerCase();
        if (!q) return true;
        /* v2.123.0: `email` is nullable since a My Work account may
           belong to a worker who has none -- searching used to call
           toLowerCase() on it unconditionally. The Employee ID is
           searchable here too, because for this class it is the number an
           administrator actually knows the person by. */
        return u.name.toLowerCase().includes(q)
            || (u.email ?? '').toLowerCase().includes(q)
            || (u.employee?.employee_id ?? '').toLowerCase().includes(q);
    });

    function toggle(user, next) {
        // v2.53.0: no quota gate. PTW Access is a permission, not a seat.
        router.put(route('settings.users.ptw-access', user.id), { ptw_access: next }, { preserveScroll: true });
    };

    // Separate endpoint, separate concept: this consumes no PTW quota and
    // grants no capability -- it only changes where the account lands.
    const toggleField = (user, next) => {
        router.put(route('settings.users.field-access', user.id), { is_field_user: next }, { preserveScroll: true });
    };

    return (
        <Card>
            <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <CardTitle>Field &amp; PTW Access</CardTitle>
                    {/* v2.71.0: says what the two switches actually do. The
                        previous one line only described PTW Access, so the
                        field-workspace switch beside it was unexplained. */}
                    {/* v2.72.0 -- TWO CAPABILITIES, NOT TWO CHECKBOXES.

                        Reported as confusing, and reading the markup the
                        reason was structural rather than verbal: the two
                        switches sat side by side with nothing saying they
                        answer different questions, so they read as a pair of
                        related settings where one probably implies the other.
                        They do not, and conflating them produces a field
                        account that cannot actually do its job. */}
                    <CardDescription>
                        Dua izin yang berbeda dan berdiri sendiri. My Work menentukan RUANG KERJA
                        yang dibuka akun tersebut; PTW Access menentukan WEWENANG membuat dan
                        mengajukan Permit To Work. Keduanya dapat diberikan terpisah.
                    </CardDescription>
                </div>
                {/* v2.53.0: a count, not a quota. PTW Access is a permission
                    granted to existing accounts, not a purchased allowance. */}
                <Badge variant="outline" className="w-fit shrink-0">
                    PTW Access &middot; {used} {used === 1 ? 'account' : 'accounts'}
                </Badge>
            </CardHeader>
            <CardContent className="p-0">

                {/* WHY an administrator would enable either -- the operating
                    model, stated once, where the decision is actually made.
                    Without it "PTW Access" is a permission name with no
                    context, and the commonest mistake is assuming HSE is the
                    role that raises permits. HSE reviews and authorises; the
                    person who raises one is whoever is responsible for the
                    work. */}
                <div className="mx-4 mb-3 rounded-lg border border-graphite-200 bg-graphite-50/70 p-3 dark:border-slate-800 dark:bg-slate-800/40">
                    <p className="text-[11px] font-semibold uppercase tracking-wide text-graphite-500 dark:text-slate-400">
                        Alur Permit To Work
                    </p>
                    <ol className="mt-2 flex flex-col gap-2 text-xs leading-relaxed text-graphite-600 sm:flex-row dark:text-slate-300">
                        <li className="flex-1 rounded-md border border-steel-200 bg-white px-2.5 py-2 dark:border-slate-700 dark:bg-slate-900">
                            <span className="block font-semibold text-navy-900 dark:text-slate-100">Foreman / PIC</span>
                            Membuat dan mengajukan PTW untuk pekerjaan yang menjadi tanggung jawabnya.
                            <span className="mt-1 block text-[11px] text-graphite-500 dark:text-slate-400">Perlu PTW Access &mdash; biasanya juga My Work.</span>
                        </li>
                        <li className="flex-1 rounded-md border border-steel-200 bg-white px-2.5 py-2 dark:border-slate-700 dark:bg-slate-900">
                            <span className="block font-semibold text-navy-900 dark:text-slate-100">HSE</span>
                            Meninjau, memverifikasi, lalu menyetujui atau mengembalikan pengajuan tersebut.
                            <span className="mt-1 block text-[11px] text-graphite-500 dark:text-slate-400">Sudah melekat pada peran HSE &mdash; tidak perlu diaktifkan di sini.</span>
                        </li>
                    </ol>
                </div>

                {/* An HSE administrator who cannot find where accounts are
                    created should be told where they are created, not left to
                    conclude the feature is missing. Shown only to someone who
                    cannot do it themselves -- a Super Admin has the User
                    Management card directly above. */}
                {! canManageUsers && (
                    <div className="mx-4 mb-3 flex items-start gap-2.5 rounded-lg border border-steel-200 bg-steel-50 p-3 text-xs leading-relaxed text-navy-800 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300">
                        <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                        <span>
                            Akun beserta email dan kata sandinya dibuat oleh Administrator organisasi di
                            <span className="font-semibold"> Settings &rarr; Users &rarr; User Management</span>.
                            Setelah akun tersebut ada, Anda mengatur PTW Access dan My Work untuk akun itu di sini.
                        </span>
                    </div>
                )}

                <div className="px-4 pb-3">
                    <div className="relative max-w-sm">
                        <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                        <Input className="pl-8" placeholder="Search users..." value={search} onChange={(e) => setSearch(e.target.value)} />
                    </div>
                </div>

                {/* Column headers, so the switches read as a capability matrix
                    rather than two loose settings per row. Hidden below sm,
                    where each row stacks and carries its own labels. */}
                <div className="hidden items-center gap-4 border-y border-graphite-100 bg-graphite-50/60 px-4 py-2 sm:flex dark:border-slate-800 dark:bg-slate-800/30">
                    <span className="min-w-0 flex-1 text-[11px] font-semibold uppercase tracking-wide text-graphite-500 dark:text-slate-400">Account</span>
                    <span className="w-[104px] shrink-0 text-center text-[11px] font-semibold uppercase tracking-wide text-graphite-500 dark:text-slate-400">
                        Land in My Work
                        <span className="block text-[10px] font-normal normal-case tracking-normal text-graphite-400">Landing preference</span>
                    </span>
                    <span className="w-[104px] shrink-0 text-center text-[11px] font-semibold uppercase tracking-wide text-graphite-500 dark:text-slate-400">
                        PTW Access
                        <span className="block text-[10px] font-normal normal-case tracking-normal text-graphite-400">Capability</span>
                    </span>
                </div>

                <div className="divide-y divide-graphite-100 dark:divide-slate-800">
                    {filtered.length === 0 ? (
                        <p className="px-4 py-4 text-sm text-graphite-400">No users match your search.</p>
                    ) : (
                        filtered.map((u) => {
                            const deptLabel = u.department_key ? departmentLabel(u.department_key) : undefined;

                            /* What the account can actually do, in one line.
                               The switches describe INPUTS; this describes the
                               OUTCOME, which is what an administrator is
                               really deciding about. */
                            const effect = u.ptw_access && u.is_field_user
                                ? 'Membuka My Work dan dapat mengajukan PTW'
                                : u.ptw_access
                                    ? 'Dapat mengajukan PTW, membuka Dashboard biasa'
                                    : u.is_field_user
                                        ? 'Membuka My Work, tanpa wewenang membuat PTW'
                                        : null;

                            return (
                                <div key={u.id} className="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:gap-4">
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-[13px] font-medium text-graphite-900 dark:text-slate-100">{u.name}</p>
                                        {/* v2.123.0: a My Work account has no
                                            department by design, so the
                                            no-department fallback read
                                            "Administrator (all)" for exactly the
                                            class that is furthest from one. It
                                            shows the register's own answer
                                            instead. */}
                                        <p className="truncate text-[11px] font-medium uppercase tracking-wide text-graphite-400 dark:text-slate-500">
                                            {u.user_type === 'my_work'
                                                ? [u.employee?.employee_id, u.employee?.position].filter(Boolean).join(' · ') || 'My Work User'
                                                : (deptLabel || 'Administrator (all)')}
                                        </p>
                                        {effect && (
                                            <p className="mt-0.5 truncate text-[11px] text-graphite-500 dark:text-slate-400">{effect}</p>
                                        )}
                                    </div>

                                    {/* v2.52.0 -- TWO DIFFERENT THINGS, and since
                                        v2.72.0 under their own headed columns.
                                        My Work decides which WORKSPACE the account
                                        lands in; PTW Access decides whether it may
                                        CREATE a Permit To Work. A field worker
                                        normally has the first and not the second; a
                                        foreman responsible for the work has both. */}
                                    <div className="flex items-center gap-4">
                                        {canFieldAccess ? (
                                            <label className="flex items-center gap-2 sm:w-[104px] sm:justify-center">
                                                <Checkbox
                                                    checked={!!u.is_field_user}
                                                    onCheckedChange={(v) => toggleField(u, Boolean(v))}
                                                    aria-label={`Land in My Work preference for ${u.name}`}
                                                />
                                                <span className="text-xs font-medium text-graphite-500 sm:hidden dark:text-slate-400">Land in My Work</span>
                                            </label>
                                        ) : (
                                            <span className="hidden sm:block sm:w-[104px]" />
                                        )}
                                        <label className="flex items-center gap-2 sm:w-[104px] sm:justify-center">
                                            <Checkbox
                                                checked={!!u.ptw_access}
                                                onCheckedChange={(v) => toggle(u, Boolean(v))}
                                                aria-label={`PTW Access for ${u.name}`}
                                            />
                                            <span className="text-xs font-medium text-graphite-500 sm:hidden dark:text-slate-400">PTW Access</span>
                                        </label>
                                    </div>
                                </div>
                            );
                        })
                    )}
                </div>
            </CardContent>
        </Card>
    );
}


/**
 * v1.10.7. `settings.users.update` has existed since this controller was
 * first built, but nothing in this tab ever called it -- there was no way
 * to edit an existing user at all (rename, change role, reset password,
 * or set the Department Restriction this same release adds to Create).
 * Same field set as Create, pre-filled, password left blank (only sent if
 * actually changed -- matches UpdateUserRequest's own `nullable` rule).
 */
function EditUserDialog({ user, onClose, assignableDepartments = [] }) {
    const { data, setData, put, processing, errors } = useForm({
        name: user.name,
        display_name: user.display_name || '',
        email: user.email,
        password: '',
        role: user.role,
        department_key: user.department_key || '',
        is_active: user.is_active,
    });

    function submit(e) {
        e.preventDefault();
        put(route('settings.users.update', user.id), { preserveScroll: true, onSuccess: onClose });
    }

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <DialogHeader><DialogTitle>Edit {user.display ?? user.name}</DialogTitle></DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    {Object.keys(errors).length > 0 && (
                        <div className="rounded-md border border-red-200 bg-red-50 p-2 text-xs text-red-700">
                            {Object.values(errors).map((msg, i) => <p key={i}>{msg}</p>)}
                        </div>
                    )}
                    <div className="space-y-1.5"><Label>Name</Label><Input value={data.name} onChange={(e) => setData('name', e.target.value)} /></div>
                    <DisplayNameField value={data.display_name} onChange={(v) => setData('display_name', v)} placeholder={data.name || 'Sama dengan nama terdaftar'} />
                    <div className="space-y-1.5"><Label>Email</Label><Input type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                        {errors.email && <p className="text-xs text-red-600">{errors.email}</p>}
                    </div>
                    <div className="space-y-1.5"><Label>New Password (leave blank to keep current)</Label><PasswordInput value={data.password} onChange={(e) => setData('password', e.target.value)} />
                        {errors.password && <p className="text-xs text-red-600">{errors.password}</p>}
                    </div>
                    <div className="space-y-1.5">
                        <Label>Role</Label>
                        <Select value={data.role} onValueChange={(v) => setData('role', v)}>
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="super_admin">Administrator</SelectItem>
                                <SelectItem value="hse">HSE</SelectItem>
                                <SelectItem value="hrd">HRD</SelectItem>
                                <SelectItem value="manager">Manager</SelectItem>
                                <SelectItem value="warehouse">Warehouse</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <DepartmentField value={data.department_key} onChange={(v) => setData('department_key', v)} options={assignableDepartments} />
                    <div className="flex items-center gap-2">
                        <Checkbox checked={data.is_active} onCheckedChange={(v) => setData('is_active', Boolean(v))} />
                        <Label className="!mt-0">Active</Label>
                    </div>
                    <DialogFooter><Button type="submit" disabled={processing}>{processing && <Loader2 className="h-4 w-4 animate-spin" />} Save</Button></DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}


/**
 * Milestone 3 (UAT #6). Assigns/unassigns a user's CUSTOM roles --
 * additive on top of their base `role` column, which this never touches
 * (see SettingsController::updateUserRoles()'s own doc comment).
 */
function UserRolesDialog({ user, customRoles, onClose }) {
    const { data, setData, put, processing } = useForm({ role_ids: user.role_ids ?? [] });

    function toggle(id) {
        setData('role_ids', data.role_ids.includes(id) ? data.role_ids.filter((i) => i !== id) : [...data.role_ids, id]);
    }

    function submit(e) {
        e.preventDefault();
        put(route('settings.users.roles', user.id), { preserveScroll: true, onSuccess: onClose });
    }

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <DialogHeader><DialogTitle>Custom Roles for {user.name}</DialogTitle></DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    {customRoles.map((r) => (
                        <label key={r.id} className="flex items-center justify-between rounded-xl border border-steel-100 bg-white/60 px-3 py-2">
                            <span className="text-sm text-graphite-700">{r.name}</span>
                            <Checkbox checked={data.role_ids.includes(r.id)} onCheckedChange={() => toggle(r.id)} />
                        </label>
                    ))}
                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            {processing && <Loader2 className="h-4 w-4 animate-spin" />} Save
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
