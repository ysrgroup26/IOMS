import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DashboardShell from '@/Components/shared/DashboardShell';
import StatusBadge from '@/Components/shared/StatusBadge';
import { FigureGroup, Figure, Ranking, NoData, Panel, Distribution } from '@/Components/shared/ManagementPanels';

const CAPA_LABELS = {
    open: 'Open',
    in_progress: 'In Progress',
    completed: 'Completed',
};

/**
 * OUTSTANDING ACTIONS (v2.83.0) -- where the management workspace stops
 * reporting and starts pointing.
 *
 * It is not a second CAPA module: every row here belongs to the module that
 * owns it, is read through that module's own model, and is only GATHERED
 * here. There is no edit action, because a manager reading a list of
 * overdue work should be sending it back to the owner, not closing it from
 * a report.
 *
 * The one genuine addition over the operational surfaces is that overdue
 * work from three different modules -- corrective actions, safety-equipment
 * inspections and P3K inspections -- is counted in the same place. Nothing
 * in IOMS could previously state a single "how much is late" figure.
 */
export default function ManagementActions({ actions }) {
    const capa = actions?.corrective_actions ?? {};
    const inspections = actions?.overdue_inspections ?? {};
    const awaiting = actions?.awaiting_decision ?? {};

    const overdueTotal = (capa.overdue ?? 0) + (inspections.safety_equipment ?? 0) + (inspections.p3k_boxes ?? 0);
    const awaitingTotal = (awaiting.permits ?? 0) + (awaiting.material_requests ?? 0) + (awaiting.leave_requests ?? 0);

    return (
        <AuthenticatedLayout>
            <Head title="Outstanding Actions" />
            <DashboardShell
                title="Outstanding Actions"
                subtitle="Semua pekerjaan yang terlambat atau menunggu keputusan, dikumpulkan dari modul yang memilikinya."
            >
                <FigureGroup title="Attention Required" description="Ringkasan lintas modul dalam satu angka." columns={4}>
                    <Figure
                        label="Overdue"
                        value={overdueTotal}
                        hint="CAPA dan inspeksi"
                        emphasis
                        tone={overdueTotal > 0 ? 'bad' : 'good'}
                    />
                    <Figure
                        label="Open CAPA"
                        value={capa.open ?? 0}
                        hint="belum diverifikasi"
                        emphasis
                        tone={(capa.open ?? 0) > 0 ? 'warn' : 'good'}
                    />
                    <Figure
                        label="Overdue Inspections"
                        value={(inspections.safety_equipment ?? 0) + (inspections.p3k_boxes ?? 0)}
                        hint="peralatan dan P3K"
                        emphasis
                        tone={((inspections.safety_equipment ?? 0) + (inspections.p3k_boxes ?? 0)) > 0 ? 'bad' : 'good'}
                    />
                    <Figure
                        label="Awaiting Decision"
                        value={awaitingTotal}
                        hint="izin, permintaan, cuti"
                        emphasis
                        tone={awaitingTotal > 0 ? 'warn' : 'neutral'}
                    />
                </FigureGroup>

                <Panel
                    title="Corrective Actions"
                    description="Tindakan perbaikan yang belum diverifikasi. Tanggal jatuh tempo terdekat lebih dulu; yang tanpa tanggal berada di akhir."
                >
                    <Ranking
                        rows={capa.rows}
                        columns={[
                            { key: 'action', label: 'Action', render: (row) => <span className="line-clamp-2">{row.action}</span> },
                            { key: 'assignee', label: 'Owner', render: (row) => row.assignee ?? '—' },
                            { key: 'priority', label: 'Priority', render: (row) => row.priority ? <StatusBadge value={row.priority} /> : '—' },
                            { key: 'status', label: 'Status', render: (row) => <StatusBadge value={row.status} /> },
                            {
                                key: 'due_date',
                                label: 'Due',
                                align: 'right',
                                render: (row) => row.due_date
                                    ? <span className={row.overdue ? 'font-semibold text-red-600 dark:text-red-400' : undefined}>{row.due_date}</span>
                                    : '—',
                            },
                        ]}
                        empty={(
                            <NoData
                                title="Nothing outstanding"
                                description="Belum ada tindakan perbaikan terbuka, atau modul CAPA belum digunakan."
                            />
                        )}
                    />
                </Panel>

                <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                    <Panel title="Open CAPA by Status" description="Tahapan tindakan perbaikan yang masih berjalan.">
                        {Object.keys(capa.by_status ?? {}).length > 0
                            ? <Distribution data={capa.by_status} labels={CAPA_LABELS} />
                            : <NoData title="No open corrective actions" description="Tidak ada tindakan perbaikan yang terbuka saat ini." />}
                    </Panel>

                    <Panel title="Awaiting Decision" description="Pekerjaan yang berhenti karena menunggu persetujuan seseorang.">
                        {awaitingTotal > 0 ? (
                            <Distribution
                                data={{
                                    permits: awaiting.permits ?? 0,
                                    material_requests: awaiting.material_requests ?? 0,
                                    leave_requests: awaiting.leave_requests ?? 0,
                                }}
                                labels={{ permits: 'Permits To Work', material_requests: 'Material Requests', leave_requests: 'Leave Requests' }}
                            />
                        ) : (
                            <NoData
                                title="Nothing awaiting a decision"
                                description="Tidak ada pengajuan yang menunggu persetujuan saat ini."
                            />
                        )}
                    </Panel>
                </div>
            </DashboardShell>
        </AuthenticatedLayout>
    );
}
