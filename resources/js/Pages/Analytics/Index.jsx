import { Head } from '@inertiajs/react';
import '@/lib/chartSetup';
import { CHART_COLORS } from '@/lib/chartSetup';
import { Pie, Bar, Line } from 'react-chartjs-2';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import EmptyState from '@/Components/shared/EmptyState';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { BarChart3 } from 'lucide-react';

const CHART_COMPONENT = { pie: Pie, bar: Bar, line: Line };

const CHART_OPTIONS = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } },
    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
};

const PIE_OPTIONS = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } },
};

/**
 * Analytics Framework (Milestone 3, Task #64). One page that renders
 * every dataset registered in config/analytics.php and currently
 * visible to this tenant (module-gated the same way the sidebar is) --
 * this page never hardcodes a chart per module; it's purely a renderer
 * over whatever App\Services\AnalyticsService::available() returns.
 * Individual dashboards (Dashboard/Index.jsx, department dashboards) can
 * fetch a single dataset via GET /analytics/{key} for an inline widget
 * without loading this whole page.
 */
export default function AnalyticsIndex({ available, datasets }) {
    if (!available || available.length === 0) {
        return (
            <AuthenticatedLayout>
                <Head title="Analytics" />
                <PageHeader title="Analytics" subtitle="Cross-module reporting datasets." />
                <Card>
                    <CardContent>
                        <EmptyState
                            icon={BarChart3}
                            title="No datasets available yet"
                            description="Analytics datasets appear here once their module is enabled and has data."
                        />
                    </CardContent>
                </Card>
            </AuthenticatedLayout>
        );
    }

    return (
        <AuthenticatedLayout>
            <Head title="Analytics" />

            <PageHeader
                title="Analytics"
                subtitle="Reusable, cross-module reporting -- every chart below is a live query, grouped by the Analytics Framework's dataset registry."
            />

            <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
                {available.map(({ key, label, chart }) => {
                    const data = datasets[key];
                    const ChartComponent = CHART_COMPONENT[chart] || Bar;
                    const isEmpty = !data?.values?.length || data.values.every((v) => !v);

                    const chartData = {
                        labels: data?.labels ?? [],
                        datasets: [{
                            label,
                            data: data?.values ?? [],
                            backgroundColor: chart === 'line' ? `${CHART_COLORS[0]}33` : CHART_COLORS,
                            borderColor: CHART_COLORS[0],
                            fill: chart === 'line',
                            tension: 0.35,
                        }],
                    };

                    return (
                        <Card key={key}>
                            <CardHeader>
                                <CardTitle>{data?.label ?? label}</CardTitle>
                                <CardDescription>Live data, updated on every page load.</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="h-72">
                                    {isEmpty ? (
                                        <p className="flex h-full items-center justify-center text-sm text-graphite-400 dark:text-slate-500">No data yet.</p>
                                    ) : (
                                        <ChartComponent data={chartData} options={chart === 'pie' ? PIE_OPTIONS : CHART_OPTIONS} />
                                    )}
                                </div>
                            </CardContent>
                        </Card>
                    );
                })}
            </div>
        </AuthenticatedLayout>
    );
}
