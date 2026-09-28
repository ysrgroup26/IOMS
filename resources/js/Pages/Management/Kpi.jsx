import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DashboardShell from '@/Components/shared/DashboardShell';
import { FigureGroup, Figure, Ranking, TrendBars, NoData, Panel } from '@/Components/shared/ManagementPanels';

const MONTHS = [
    { value: '', label: 'Full year' },
    ...Array.from({ length: 12 }, (_, index) => ({
        value: String(index + 1),
        label: new Date(2000, index, 1).toLocaleDateString('en-US', { month: 'long' }),
    })),
];

/**
 * COMPANY KPI (v2.83.0) -- the same KPI catalogue the operational
 * Dashboard reads, seen a different way.
 *
 * The Dashboard shows this month's totals. This page shows a period, a
 * twelve-month shape, and the same figures split by department -- which is
 * the management question: not "what is the number" but "where is it coming
 * from, and is it moving".
 *
 * It computes nothing client-side. The period is a server round trip, so
 * the numbers on screen are always the numbers the server would report.
 */
export default function ManagementKpi({ kpi, departments, period, years }) {
    function setPeriod(next) {
        router.get(route('management.kpi'), next, { preserveState: true, preserveScroll: true, replace: true });
    }

    const categories = kpi?.categories ?? [];

    /* WHICH CATEGORY THE TREND PLOTS, and why it is not simply the first.
       Twelve bars carrying eight stacked series is unreadable, so one
       category is charted and the rest are stated as totals above. Taking
       `categories[0]` looked right and was wrong in practice: the first
       configured category is Fatality, which is zero in a good year -- so
       the chart refused to draw and told the reader there were "no KPI
       records for this year" directly underneath a table full of them.
       The category with the most movement is the one worth a shape. */
    const plotted = categories.reduce(
        (best, category) => (best === null || category.total > best.total ? category : best),
        null,
    );

    return (
        <AuthenticatedLayout>
            <Head title="Company KPI" />
            <DashboardShell
                title="Company KPI"
                subtitle="KPI perusahaan untuk satu periode, dan kontribusi masing-masing department."
                actions={
                    <div className="flex items-center gap-2">
                        <label className="sr-only" htmlFor="kpi-year">Year</label>
                        <select
                            id="kpi-year"
                            value={period?.year ?? ''}
                            onChange={(event) => setPeriod({ year: event.target.value, month: period?.month ?? '' })}
                            className="h-8 rounded-lg border border-graphite-200 bg-white px-2 text-xs font-medium text-graphite-700 outline-none focus:border-brand-400 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300"
                        >
                            {(years ?? []).map((year) => <option key={year} value={year}>{year}</option>)}
                        </select>
                        <label className="sr-only" htmlFor="kpi-month">Month</label>
                        <select
                            id="kpi-month"
                            value={period?.month ?? ''}
                            onChange={(event) => setPeriod({ year: period?.year, month: event.target.value })}
                            className="h-8 rounded-lg border border-graphite-200 bg-white px-2 text-xs font-medium text-graphite-700 outline-none focus:border-brand-400 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300"
                        >
                            {MONTHS.map((month) => <option key={month.value} value={month.value}>{month.label}</option>)}
                        </select>
                    </div>
                }
            >
                {categories.length === 0 ? (
                    <NoData
                        title="No KPI categories configured"
                        description="Atur kategori KPI di Admin Space lebih dulu; halaman ini membaca katalog KPI yang sama dengan Dashboard."
                    />
                ) : (
                    <>
                        <FigureGroup
                            title="Period Totals"
                            description="Total seluruh department untuk periode yang dipilih."
                            columns={categories.length >= 5 ? 5 : categories.length >= 4 ? 4 : 3}
                        >
                            {categories.map((category) => (
                                <Figure
                                    key={category.id}
                                    label={category.short_label || category.name}
                                    value={category.total}
                                    hint={category.is_negative ? 'lebih rendah lebih baik' : undefined}
                                    tone={category.is_negative && category.total > 0 ? 'warn' : 'neutral'}
                                    emphasis
                                />
                            ))}
                        </FigureGroup>

                        <Panel title="Twelve-Month Shape" description={`Total bulanan ${plotted?.name ?? 'KPI'} sepanjang ${period?.year}.`}>
                            <TrendBars
                                points={(kpi?.trend ?? []).map((point) => ({
                                    label: point.label,
                                    short_label: point.label,
                                    primary: plotted ? (point.totals?.[plotted.id] ?? 0) : 0,
                                }))}
                                series={[{ key: 'primary', label: plotted?.name ?? 'KPI', className: 'bg-brand-500/80' }]}
                                empty={(
                                    <NoData
                                        title="No KPI records for this year"
                                        description="Grafik terisi setelah ada input KPI pada tahun yang dipilih."
                                    />
                                )}
                            />
                        </Panel>

                        <Panel title="KPI by Department" description="Kontribusi tiap department pada periode yang dipilih.">
                            <Ranking
                                rows={kpi?.departments}
                                columns={[
                                    { key: 'name', label: 'Department' },
                                    ...categories.slice(0, 4).map((category) => ({
                                        key: `c${category.id}`,
                                        label: category.short_label || category.name,
                                        align: 'right',
                                        render: (row) => row.totals?.[category.id] ?? 0,
                                    })),
                                    { key: 'total', label: 'Total', align: 'right' },
                                ]}
                                empty={(
                                    <NoData
                                        title="No KPI input for this period"
                                        description="Belum ada catatan KPI pada periode ini. Coba pilih periode lain atau mulai input KPI."
                                    />
                                )}
                            />
                        </Panel>
                    </>
                )}

                <Panel title="Department Profile" description="Jumlah tenaga kerja dan permintaan terbuka per department, sebagai konteks angka KPI.">
                    <Ranking
                        rows={departments}
                        columns={[
                            { key: 'name', label: 'Department' },
                            { key: 'headcount', label: 'Headcount', align: 'right' },
                            { key: 'kpi_total', label: 'KPI Total', align: 'right' },
                            { key: 'open_requests', label: 'Open Requests', align: 'right' },
                        ]}
                        empty={(
                            <NoData
                                title="No departments configured"
                                description="Tambahkan department di Admin Space agar profil ini dapat ditampilkan."
                            />
                        )}
                    />
                </Panel>
            </DashboardShell>
        </AuthenticatedLayout>
    );
}
