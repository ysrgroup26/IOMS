import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DashboardShell from '@/Components/shared/DashboardShell';
import { FigureGroup, Figure, TrendBars, NoData, Panel, Distribution } from '@/Components/shared/ManagementPanels';

const SEVERITY_LABELS = {
    critical: 'Critical',
    high: 'High',
    medium: 'Medium',
    low: 'Low',
    minor: 'Minor',
};

const STATUS_LABELS = {
    reported: 'Reported',
    investigating: 'Investigating',
    closed: 'Closed',
};

const DOCUMENT_LABELS = {
    draft: 'Draft',
    review: 'In Review',
    approved: 'Approved',
    effective: 'Effective',
    obsolete: 'Obsolete',
};

/**
 * HSE PERFORMANCE, AT MANAGEMENT LEVEL (v2.83.0).
 *
 * The HSE Overview already answers "what does the HSE team need to do
 * today". This page answers a different question -- is safety performance
 * improving -- and so it is built from a twelve-month series, leading
 * against lagging indicators, and the document state that evidences it.
 *
 * NO SAFETY INDEX AND NO TRIR. Both are standard and both need a
 * denominator IOMS does not reliably hold for every tenant (exposure hours
 * across contractors as well as employees). A rate computed from a partial
 * denominator would be worse than no rate, because it would be quoted.
 */
export default function ManagementHse({ hse, compliance }) {
    const permits = hse?.permits ?? {};
    const observations = hse?.observations ?? {};

    return (
        <AuthenticatedLayout>
            <Head title="HSE Performance" />
            <DashboardShell
                title="HSE Performance"
                subtitle="Tren keselamatan perusahaan selama dua belas bulan terakhir, beserta status dokumen terkendali."
            >
                <FigureGroup
                    title="Safety Position"
                    description="Posisi keselamatan perusahaan saat ini."
                    columns={4}
                >
                    <Figure
                        label="Days Since Incident"
                        value={hse?.days_since_last_incident}
                        hint={hse?.days_since_last_incident === null ? 'Belum pernah tercatat' : 'hari'}
                        tone={hse?.days_since_last_incident === null ? 'neutral' : 'good'}
                        emphasis
                    />
                    <Figure
                        label="Open Observations"
                        value={observations.open ?? 0}
                        hint={`${observations.total ?? 0} tercatat`}
                        tone={(observations.open ?? 0) > 0 ? 'warn' : 'good'}
                        emphasis
                    />
                    <Figure
                        label="Toolbox Meetings"
                        value={hse?.toolbox_meetings ?? 0}
                        hint="dua belas bulan terakhir"
                        emphasis
                    />
                    <Figure
                        label="Active Permits"
                        value={permits.available ? permits.active : null}
                        hint={permits.available ? `${permits.awaiting_approval} menunggu persetujuan` : 'Belum ada izin kerja'}
                        emphasis
                    />
                </FigureGroup>

                <Panel
                    title="Incidents and Observations"
                    description="Indikator tertinggal (insiden) dibandingkan indikator memimpin (observasi). Observasi yang naik sementara insiden turun adalah pola yang diharapkan."
                >
                    <TrendBars
                        points={hse?.trend}
                        series={[
                            { key: 'incidents', label: 'Incidents', className: 'bg-red-500/85' },
                            { key: 'observations', label: 'Observations', className: 'bg-brand-500/80' },
                        ]}
                        empty={(
                            <NoData
                                title="No safety records yet"
                                description="Grafik ini terisi setelah tim mencatat insiden atau observasi keselamatan."
                            />
                        )}
                    />
                </Panel>

                <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
                    <Panel title="Open Incidents by Severity" description="Sebaran keparahan insiden yang masih terbuka.">
                        {Object.keys(hse?.by_severity ?? {}).length > 0
                            ? <Distribution data={hse.by_severity} labels={SEVERITY_LABELS} />
                            : <NoData title="No open incidents" description="Tidak ada insiden terbuka saat ini." />}
                    </Panel>

                    <Panel title="Incidents by Status" description="Seluruh insiden tercatat menurut tahap penanganannya.">
                        {Object.keys(hse?.by_status ?? {}).length > 0
                            ? <Distribution data={hse.by_status} labels={STATUS_LABELS} />
                            : <NoData title="No incidents recorded" description="Modul Incident Management belum digunakan." />}
                    </Panel>

                    <Panel title="Permit Activity" description="Status izin kerja di seluruh perusahaan.">
                        {permits.available ? (
                            <Distribution
                                data={{ active: permits.active, awaiting_approval: permits.awaiting_approval, closed: permits.closed }}
                                labels={{ active: 'Active', awaiting_approval: 'Awaiting Approval', closed: 'Closed' }}
                            />
                        ) : (
                            <NoData title="No permits raised" description="Angka terisi setelah tim menerbitkan izin kerja." />
                        )}
                    </Panel>
                </div>

                <Panel
                    title="Controlled Documents"
                    description="Status dokumen terkendali. IOMS mencatat dokumen yang ada dan statusnya, bukan dokumen apa yang diwajibkan — jadi tidak ada skor kepatuhan di sini."
                >
                    {compliance?.available ? (
                        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                            <Distribution data={compliance.documents_by_status} labels={DOCUMENT_LABELS} />
                            <div className="grid grid-cols-2 divide-x divide-graphite-100 dark:divide-slate-800">
                                <Figure label="Effective" value={compliance.effective_documents} hint="berlaku saat ini" emphasis tone="good" />
                                <Figure
                                    label="In Review"
                                    value={compliance.documents_in_review}
                                    hint="menunggu peninjauan"
                                    emphasis
                                    tone={compliance.documents_in_review > 0 ? 'warn' : 'neutral'}
                                />
                            </div>
                        </div>
                    ) : (
                        <NoData
                            title="No controlled documents"
                            description="Panel ini terisi setelah dokumen terkendali didaftarkan pada modul Document Control."
                        />
                    )}
                </Panel>
            </DashboardShell>
        </AuthenticatedLayout>
    );
}
