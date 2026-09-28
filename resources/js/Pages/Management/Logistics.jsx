import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DashboardShell from '@/Components/shared/DashboardShell';
import { FigureGroup, Figure, Ranking, NoData, Panel, Distribution } from '@/Components/shared/ManagementPanels';

const REQUEST_LABELS = {
    draft: 'Draft',
    submitted: 'Submitted',
    approved: 'Approved',
    consolidating: 'Consolidating',
    processing: 'Processing',
    completed: 'Completed',
    rejected: 'Rejected',
    cancelled: 'Cancelled',
};

/**
 * LOGISTICS AND INVENTORY, AT MANAGEMENT LEVEL (v2.83.0).
 *
 * NO INVENTORY VALUE. The obvious management metric for a warehouse is what
 * the stock is worth, and IOMS deliberately does not hold a unit cost on
 * `items` -- so a value here would have to be invented, which is exactly
 * what this workspace refuses to do. What it CAN say is authoritative:
 * which items are below the minimum their own master record declares, how
 * much demand is open, and how much came in this month.
 */
export default function ManagementLogistics({ logistics }) {
    const requests = logistics?.material_requests ?? {};
    const items = logistics?.items ?? {};

    if (! logistics?.available) {
        return (
            <AuthenticatedLayout>
                <Head title="Logistics & Inventory" />
                <DashboardShell title="Logistics & Inventory" subtitle="Posisi stok dan permintaan material perusahaan.">
                    <NoData
                        title="No logistics records yet"
                        description="Halaman ini terisi setelah Item Master diisi atau permintaan material pertama diajukan."
                    />
                </DashboardShell>
            </AuthenticatedLayout>
        );
    }

    return (
        <AuthenticatedLayout>
            <Head title="Logistics & Inventory" />
            <DashboardShell
                title="Logistics & Inventory"
                subtitle="Posisi stok terhadap batas minimum, permintaan material yang terbuka, dan penerimaan barang bulan ini."
            >
                <FigureGroup title="Inventory Position" description="Cakupan data barang dan lokasi penyimpanan." columns={4}>
                    <Figure label="Active Items" value={items.active} hint={`${items.total} tercatat`} emphasis />
                    <Figure label="Warehouses" value={logistics.warehouses} hint="lokasi penyimpanan" emphasis />
                    <Figure
                        label="Below Minimum"
                        value={logistics.below_minimum_count}
                        hint="barang di bawah batas minimum"
                        emphasis
                        tone={logistics.below_minimum_count > 0 ? 'bad' : 'good'}
                    />
                    <Figure
                        label="Goods Receipts"
                        value={logistics.goods_receipts_this_month}
                        hint="bulan ini"
                        emphasis
                    />
                </FigureGroup>

                <Panel
                    title="Stock Below Minimum"
                    description="Barang yang jumlahnya di bawah batas minimum pada master datanya sendiri, kekurangan terbesar lebih dulu."
                >
                    <Ranking
                        rows={logistics.below_minimum}
                        columns={[
                            { key: 'name', label: 'Item' },
                            { key: 'item_code', label: 'Code', render: (row) => row.item_code ?? '—' },
                            { key: 'held', label: 'On Hand', align: 'right', render: (row) => `${row.held} ${row.unit ?? ''}`.trim() },
                            { key: 'min_stock', label: 'Minimum', align: 'right', render: (row) => `${row.min_stock} ${row.unit ?? ''}`.trim() },
                            {
                                key: 'gap',
                                label: 'Shortfall',
                                align: 'right',
                                render: (row) => (
                                    <span className="font-semibold text-red-600 dark:text-red-400">
                                        {Math.round((row.min_stock - row.held) * 100) / 100}
                                    </span>
                                ),
                            },
                        ]}
                        empty={(
                            <NoData
                                title="Every item is at or above minimum"
                                description="Tidak ada barang di bawah batas minimum, atau batas minimum belum diatur pada Item Master."
                            />
                        )}
                    />
                </Panel>

                <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                    <FigureGroup title="Material Demand" description="Permintaan material yang belum selesai dan yang masuk bulan ini." columns={2}>
                        <Figure
                            label="Open Requests"
                            value={requests.available ? requests.open : null}
                            hint={requests.available ? 'belum selesai' : 'Belum ada permintaan material'}
                            emphasis
                            tone={requests.available && requests.open > 0 ? 'warn' : 'neutral'}
                        />
                        <Figure
                            label="Raised This Month"
                            value={requests.available ? requests.this_month : null}
                            hint={requests.available ? 'permintaan' : '—'}
                            emphasis
                        />
                    </FigureGroup>

                    <Panel title="Requests by Status" description="Sebaran seluruh permintaan material menurut tahapannya.">
                        {Object.keys(requests.by_status ?? {}).length > 0
                            ? <Distribution data={requests.by_status} labels={REQUEST_LABELS} />
                            : (
                                <NoData
                                    title="No material requests"
                                    description="Panel ini terisi setelah department mengajukan permintaan material."
                                />
                            )}
                    </Panel>
                </div>
            </DashboardShell>
        </AuthenticatedLayout>
    );
}
