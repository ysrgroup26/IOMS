import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import StatusBadge from '@/Components/shared/StatusBadge';
import ActivityList from '@/Components/shared/ActivityList';
import { FigureGroup, Figure, NoData, Panel, Distribution } from '@/Components/shared/ManagementPanels';
import {
    Settings, Users, Lock, Building2, ShieldCheck, DollarSign, ClipboardList,
    ArrowRight, AlertTriangle,
} from 'lucide-react';

const ROLE_LABELS = {
    super_admin: 'Super Admin',
    hse: 'HSE',
    hrd: 'HRD',
    manager: 'Manager',
    warehouse: 'Warehouse',
    account: 'Account',
};

const LIFECYCLE_TONE = {
    active: 'good',
    grace: 'warn',
    lapsed: 'bad',
    suspended: 'bad',
    cancelled: 'bad',
};

/**
 * ADMIN SPACE — ADMINISTRATION OVERVIEW (v2.83.0).
 *
 * Deliberately does NOT look like Management. Management gets the
 * DashboardShell hero because it is an executive surface; this page uses
 * the flat `administration` PageHeader, which is the same chrome every
 * configuration screen in IOMS wears. A customer should be able to tell at
 * a glance whether they are looking at how the company is doing or at how
 * the system is configured.
 *
 * FOUR QUESTIONS, IN THE ORDER AN ADMINISTRATOR ASKS THEM:
 *
 *   ACCESS         who can get in, and how much room is left
 *   SECURITY       how those accounts are protected, and which are dormant
 *   ORGANIZATION   the structure everything else hangs off
 *   COMMERCIAL     the subscription that pays for it
 *
 * It owns nothing and duplicates no form. Every figure is read from the
 * table that already holds it and every action links to the existing
 * Settings tab, Activity Center or Billing page -- with their routes,
 * validation and authorization untouched.
 */
export default function AdminOverview({ organization, access, security, structure, subscription, recent_activity: recentActivity }) {
    const seatLimit = access?.seat_limit;
    const remaining = access?.remaining_slots;
    const unitLimit = structure?.operating_unit_limit;

    return (
        <AuthenticatedLayout>
            <Head title="Administration Overview" />

            <div className="space-y-3">
                <PageHeader
                    title="Administration Overview"
                    subtitle="Administrasi dan pengelolaan organisasi, akses, keamanan, serta langganan perusahaan Anda."
                    icon={Settings}
                    kind="administration"
                >
                    <div className="flex flex-wrap items-center gap-2">
                        <Link
                            href={route('settings.index', { tab: 'users' })}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-graphite-200 bg-white px-3 py-1.5 text-xs font-medium text-graphite-700 transition-colors hover:bg-graphite-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300"
                        >
                            <Users className="h-3.5 w-3.5" /> Users & Access
                        </Link>
                        <Link
                            href={route('subscription.billing')}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-graphite-200 bg-white px-3 py-1.5 text-xs font-medium text-graphite-700 transition-colors hover:bg-graphite-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300"
                        >
                            <DollarSign className="h-3.5 w-3.5" /> Subscription & Billing
                        </Link>
                    </div>
                </PageHeader>

                {/* The organization itself, stated plainly. An administrator
                    who manages more than one IOMS tenant needs to know which
                    one they are configuring before they change anything. */}
                <Panel title="Organization" description="Identitas organisasi yang sedang Anda kelola.">
                    <div className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                        <div>
                            <p className="text-[11px] font-medium uppercase tracking-wide text-graphite-400">Name</p>
                            <p className="font-semibold text-graphite-900 dark:text-slate-100">{organization?.name ?? '—'}</p>
                        </div>
                        <div>
                            <p className="text-[11px] font-medium uppercase tracking-wide text-graphite-400">Identifier</p>
                            <p className="font-mono text-xs text-graphite-600 dark:text-slate-400">{organization?.slug ?? '—'}</p>
                        </div>
                        <div>
                            <p className="text-[11px] font-medium uppercase tracking-wide text-graphite-400">Account Status</p>
                            <p><StatusBadge value={organization?.status ?? 'unknown'} /></p>
                        </div>
                        <div>
                            <p className="text-[11px] font-medium uppercase tracking-wide text-graphite-400">Operating Units</p>
                            <p className="font-semibold tabular-nums text-graphite-900 dark:text-slate-100">
                                {structure?.operating_units ?? 0}{unitLimit ? ` / ${unitLimit}` : ''}
                            </p>
                        </div>
                    </div>
                    {organization?.is_demo && (
                        <div className="mt-3 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 dark:border-amber-900 dark:bg-amber-950/30">
                            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" aria-hidden="true" />
                            <p className="text-[11px] text-amber-800 dark:text-amber-300">
                                Ini adalah organisasi demo. Data di dalamnya dapat direset dan tidak dimaksudkan untuk penggunaan produksi.
                            </p>
                        </div>
                    )}
                </Panel>

                {/* ACCESS. The same capacity numbers the customer's Billing
                    page and Master Admin read -- one calculation, three
                    surfaces (ADR 043). */}
                <FigureGroup
                    title="Access"
                    description="Akun login aktif dan kapasitas yang tersisa. Perangkat tidak dihitung — satu akun boleh masuk dari beberapa perangkat."
                    columns={5}
                    actions={
                        <Link href={route('settings.index', { tab: 'users' })} className="inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">
                            Manage users <ArrowRight className="h-3 w-3" />
                        </Link>
                    }
                >
                    <Figure label="Active Users" value={access?.active_users ?? 0} hint="akun login aktif" emphasis />
                    <Figure
                        label="Capacity"
                        value={seatLimit === null || seatLimit === undefined ? 'Unlimited' : seatLimit}
                        hint={access?.included_users !== null && access?.included_users !== undefined
                            ? `${access.included_users} termasuk paket + ${access.additional_users ?? 0} tambahan`
                            : 'tanpa batas'}
                        emphasis
                    />
                    <Figure
                        label="Remaining"
                        value={remaining === null || remaining === undefined ? 'Unlimited' : remaining}
                        hint={remaining === 0 ? 'kapasitas penuh' : 'slot tersisa'}
                        emphasis
                        tone={remaining === 0 ? 'warn' : 'neutral'}
                    />
                    <Figure label="Inactive" value={access?.inactive_users ?? 0} hint="tidak memakai slot" emphasis />
                    <Figure label="Custom Roles" value={structure?.custom_roles ?? 0} hint="di luar peran bawaan" emphasis />
                </FigureGroup>

                <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                    <Panel
                        title="Users by Role"
                        description="Sebaran peran seluruh akun pada organisasi ini, termasuk akun non-aktif."
                        actions={
                            <Link href={route('settings.index', { tab: 'roles' })} className="inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">
                                Roles & Permissions <ArrowRight className="h-3 w-3" />
                            </Link>
                        }
                    >
                        {Object.keys(access?.by_role ?? {}).length > 0
                            ? <Distribution data={access.by_role} labels={ROLE_LABELS} />
                            : <NoData title="No accounts recorded" description="Belum ada akun selain akun Anda sendiri." />}
                    </Panel>

                    <FigureGroup
                        title="Security Posture"
                        description="Fakta tentang akun yang ada — bukan skor. IOMS hanya melaporkan apa yang dapat dijawabnya."
                        columns={3}
                    >
                        <Figure
                            label="Never Signed In"
                            value={security?.never_signed_in ?? 0}
                            hint="akun belum pernah dipakai"
                            tone={(security?.never_signed_in ?? 0) > 0 ? 'warn' : 'good'}
                        />
                        <Figure
                            label="Dormant 90 Days"
                            value={security?.dormant_90_days ?? 0}
                            hint="aktif tapi tidak digunakan"
                            tone={(security?.dormant_90_days ?? 0) > 0 ? 'warn' : 'good'}
                        />
                        <Figure label="Active 30 Days" value={security?.signed_in_30_days ?? 0} hint="masuk dalam 30 hari" tone="good" />
                        <Figure label="Google Sign-In" value={security?.google_linked ?? 0} hint="akun tertaut Google" />
                        <Figure label="Password Only" value={security?.password_only ?? 0} hint="tanpa Google" />
                        <Figure
                            label="Unverified Email"
                            value={security?.unverified_email ?? 0}
                            hint="alamat belum dikonfirmasi"
                            tone={(security?.unverified_email ?? 0) > 0 ? 'warn' : 'good'}
                        />
                    </FigureGroup>
                </div>

                {/* COMMERCIAL. `stateSnapshot()` spread whole, so this panel
                    cannot claim "Active" while the customer's own Billing
                    page says grace (ADR 033 / 041). */}
                <Panel
                    title="Subscription"
                    description="Status langganan organisasi ini, dibaca dari sumber yang sama dengan halaman Billing."
                    actions={
                        <Link href={route('subscription.billing')} className="inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">
                            Open billing <ArrowRight className="h-3 w-3" />
                        </Link>
                    }
                >
                    {subscription ? (
                        <div className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-5">
                            <div>
                                <p className="text-[11px] font-medium uppercase tracking-wide text-graphite-400">Plan</p>
                                <p className="font-semibold text-graphite-900 dark:text-slate-100">{subscription.plan_name ?? '—'}</p>
                            </div>
                            <div>
                                <p className="text-[11px] font-medium uppercase tracking-wide text-graphite-400">Lifecycle</p>
                                <p><StatusBadge value={subscription.lifecycle_state} /></p>
                            </div>
                            <div>
                                <p className="text-[11px] font-medium uppercase tracking-wide text-graphite-400">Billing Cycle</p>
                                {/* The stored value is a lowercase key
                                    ('monthly'/'yearly'); this is a display
                                    position, so it is presented as a word
                                    rather than as the column's contents. */}
                                <p className="font-semibold capitalize text-graphite-900 dark:text-slate-100">{subscription.billing_cycle ?? '—'}</p>
                            </div>
                            <div>
                                <p className="text-[11px] font-medium uppercase tracking-wide text-graphite-400">Period Ends</p>
                                <p className="font-semibold tabular-nums text-graphite-900 dark:text-slate-100">
                                    {subscription.is_lifetime ? 'Lifetime' : (subscription.period_ends_at ?? '—')}
                                </p>
                            </div>
                            <div>
                                <p className="text-[11px] font-medium uppercase tracking-wide text-graphite-400">Billing Mode</p>
                                <p className="font-semibold text-graphite-900 dark:text-slate-100">{subscription.billing_mode_label ?? '—'}</p>
                            </div>
                            {subscription.lifecycle_state && LIFECYCLE_TONE[subscription.lifecycle_state] !== 'good' && (
                                <p className="col-span-2 text-[11px] text-amber-700 sm:col-span-5 dark:text-amber-400">
                                    {subscription.lifecycle_state === 'grace'
                                        ? `Masa tenggang berakhir ${subscription.grace_ends_at ?? 'segera'}. Perpanjang langganan agar akses penuh tetap berjalan.`
                                        : 'Akses tulis sedang dibatasi. Buka halaman Billing untuk memperpanjang langganan.'}
                                </p>
                            )}
                        </div>
                    ) : (
                        <NoData
                            title="No subscription on record"
                            description="Organisasi ini belum memiliki catatan langganan. Hubungi dukungan IOMS bila ini tidak sesuai."
                        />
                    )}
                </Panel>

                <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
                    <Panel
                        className="lg:col-span-2"
                        title="Recent Administrative Activity"
                        description="Sepuluh catatan terakhir dari jejak audit. Riwayat lengkap dan penyaringan ada di Audit Logs."
                        actions={
                            <Link href={route('activity-center.index')} className="inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">
                                Audit Logs <ArrowRight className="h-3 w-3" />
                            </Link>
                        }
                    >
                        {recentActivity?.length > 0
                            ? (
                                <ActivityList
                                    items={recentActivity}
                                    renderItem={(entry) => (
                                        <div className="flex items-start justify-between gap-3 py-2">
                                            <div className="min-w-0">
                                                <p className="truncate text-sm text-graphite-800 dark:text-slate-200">{entry.description}</p>
                                                <p className="text-[11px] text-graphite-400 dark:text-slate-500">{entry.user ?? 'System'}</p>
                                            </div>
                                            <span className="shrink-0 text-[11px] tabular-nums text-graphite-400 dark:text-slate-500">
                                                {new Date(entry.created_at).toLocaleDateString('en-GB', { day: '2-digit', month: 'short' })}
                                            </span>
                                        </div>
                                    )}
                                />
                            )
                            : <NoData title="No activity recorded yet" description="Jejak audit terisi sendiri saat pengguna mulai bekerja di IOMS." />}
                    </Panel>

                    <Panel title="Administration" description="Area administrasi lain pada organisasi ini.">
                        <div className="space-y-1.5">
                            {[
                                { icon: Users, label: 'Users & Access', href: route('settings.index', { tab: 'users' }) },
                                { icon: Lock, label: 'Roles & Permissions', href: route('settings.index', { tab: 'roles' }) },
                                { icon: Building2, label: 'Operating Units', href: route('settings.index', { tab: 'companies' }) },
                                { icon: ShieldCheck, label: 'Departments & Positions', href: route('settings.index', { tab: 'departments' }) },
                                { icon: ClipboardList, label: 'Audit Logs', href: route('activity-center.index') },
                                { icon: Settings, label: 'Module Management', href: route('settings.index', { tab: 'modules' }) },
                            ].map((entry) => (
                                <Link
                                    key={entry.label}
                                    href={entry.href}
                                    className="flex items-center gap-2.5 rounded-lg border border-graphite-100 px-3 py-2 text-sm text-graphite-700 transition-colors hover:border-brand-200 hover:bg-steel-50/70 dark:border-slate-800 dark:text-slate-300 dark:hover:border-brand-900"
                                >
                                    <entry.icon className="h-4 w-4 shrink-0 text-graphite-400" />
                                    <span className="min-w-0 truncate font-medium">{entry.label}</span>
                                    <ArrowRight className="ml-auto h-3.5 w-3.5 shrink-0 text-graphite-300" />
                                </Link>
                            ))}
                        </div>
                    </Panel>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
