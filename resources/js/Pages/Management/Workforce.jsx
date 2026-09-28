import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DashboardShell from '@/Components/shared/DashboardShell';
import { FigureGroup, Figure, Ranking, NoData, Panel, Distribution } from '@/Components/shared/ManagementPanels';

/**
 * The vocabulary is Employee::EMPLOYMENT_TYPE_* -- Indonesian labour-law
 * terms, kept as terms because PKWT and PKWTT are what an HR officer here
 * actually says and calling them "Contract" and "Permanent" would lose the
 * legal distinction. The gloss follows in parentheses, which is the same
 * treatment every established term in IOMS gets (HSE, PPE, JSA, CAPA).
 *
 * Browser-verified: without this map the panel rendered a bare "pkwtt".
 */
const EMPLOYMENT_LABELS = {
    pkwtt: 'PKWTT (Permanent)',
    pkwt: 'PKWT (Fixed Term)',
    daily: 'Daily',
    intern: 'Intern',
    pkl: 'PKL',
    contractor: 'Contractor',
    outsource: 'Outsourced',
};

/**
 * WORKFORCE OVERVIEW (v2.83.0) -- the company's people, as capacity and as
 * commitments falling due.
 *
 * Reads the Employee, Man-Hour, Leave and Competency records that already
 * exist. It is careful about one distinction the product depends on: these
 * are EMPLOYEE records, which are not login accounts and are not what the
 * subscription charges for (ADR 043). An organization of four hundred
 * employees may hold ten users, and this page is about the four hundred --
 * the ten are in Admin Space.
 */
export default function ManagementWorkforce({ workforce }) {
    const headcount = workforce?.headcount ?? {};
    const leave = workforce?.leave ?? {};
    const manHours = workforce?.man_hours_this_month ?? {};

    if (! workforce?.available) {
        return (
            <AuthenticatedLayout>
                <Head title="Workforce" />
                <DashboardShell title="Workforce" subtitle="Komposisi dan kapasitas tenaga kerja perusahaan.">
                    <NoData
                        title="No employee records yet"
                        description="Halaman ini terisi setelah data karyawan ditambahkan pada modul Employees. Tidak ada angka yang ditampilkan sebelum ada data nyata."
                    />
                </DashboardShell>
            </AuthenticatedLayout>
        );
    }

    return (
        <AuthenticatedLayout>
            <Head title="Workforce" />
            <DashboardShell
                title="Workforce"
                subtitle="Komposisi tenaga kerja, kapasitas jam kerja, dan komitmen yang akan jatuh tempo."
            >
                <FigureGroup title="Headcount" description="Jumlah karyawan aktif dan yang tercatat seluruhnya." columns={4}>
                    <Figure label="Active" value={headcount.active} hint="karyawan aktif" emphasis tone="good" />
                    <Figure label="Recorded" value={headcount.total} hint="termasuk non-aktif" emphasis />
                    <Figure
                        label="Contracts Expiring"
                        value={workforce.contracts_expiring_60_days}
                        hint="dalam 60 hari"
                        emphasis
                        tone={workforce.contracts_expiring_60_days > 0 ? 'warn' : 'neutral'}
                    />
                    <Figure
                        label="Certificates Expiring"
                        value={workforce.competencies_expiring_90_days}
                        hint="dalam 90 hari"
                        emphasis
                        tone={workforce.competencies_expiring_90_days > 0 ? 'warn' : 'neutral'}
                    />
                </FigureGroup>

                <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                    <Panel title="Headcount by Department" description="Department dengan jumlah karyawan aktif terbanyak lebih dulu.">
                        <Ranking
                            rows={workforce.by_department}
                            columns={[
                                { key: 'name', label: 'Department' },
                                { key: 'headcount', label: 'Active', align: 'right' },
                            ]}
                            empty={(
                                <NoData
                                    title="No department assignments"
                                    description="Karyawan belum dikaitkan ke department manapun, jadi perbandingan ini belum dapat dihitung."
                                />
                            )}
                        />
                    </Panel>

                    <Panel title="Employment Type" description="Komposisi status kepegawaian karyawan aktif.">
                        {Object.keys(workforce.by_employment_type ?? {}).length > 0
                            ? <Distribution data={workforce.by_employment_type} labels={EMPLOYMENT_LABELS} />
                            : (
                                <NoData
                                    title="Employment type not recorded"
                                    description="Isi status kepegawaian pada data karyawan agar komposisi ini muncul."
                                />
                            )}
                    </Panel>
                </div>

                <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                    <FigureGroup
                        title="Man-Hours This Month"
                        description="Jam kerja yang tercatat bulan ini. Angka ini hanya mencakup jam yang benar-benar diinput."
                        columns={3}
                    >
                        <Figure
                            label="Regular"
                            value={manHours.available ? Math.round(manHours.regular).toLocaleString('id-ID') : null}
                            hint={manHours.available ? 'jam' : 'Belum ada catatan'}
                            emphasis
                        />
                        <Figure
                            label="Overtime"
                            value={manHours.available ? Math.round(manHours.overtime).toLocaleString('id-ID') : null}
                            hint={manHours.available ? 'jam' : '—'}
                            emphasis
                            tone={manHours.available && manHours.overtime > manHours.regular * 0.25 ? 'warn' : 'neutral'}
                        />
                        <Figure
                            label="People Logged"
                            value={manHours.available ? manHours.people : null}
                            hint={manHours.available ? 'karyawan' : '—'}
                            emphasis
                        />
                    </FigureGroup>

                    <FigureGroup title="Leave" description="Pengajuan cuti yang perlu keputusan dan yang sudah disetujui bulan ini." columns={2}>
                        <Figure
                            label="Awaiting Approval"
                            value={leave.available ? leave.awaiting_approval : null}
                            hint={leave.available ? 'pengajuan' : 'Belum ada pengajuan cuti'}
                            emphasis
                            tone={leave.available && leave.awaiting_approval > 0 ? 'warn' : 'neutral'}
                        />
                        <Figure
                            label="Approved This Month"
                            value={leave.available ? leave.approved_this_month : null}
                            hint={leave.available ? 'pengajuan' : '—'}
                            emphasis
                        />
                    </FigureGroup>
                </div>
            </DashboardShell>
        </AuthenticatedLayout>
    );
}
