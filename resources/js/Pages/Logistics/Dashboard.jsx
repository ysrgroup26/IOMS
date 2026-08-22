import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DashboardShell from '@/Components/shared/DashboardShell';
import StatCard from '@/Components/shared/StatCard';
import ActivityList from '@/Components/shared/ActivityList';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import ModuleCard from '@/Components/shared/ModuleCard';
import DepartmentCalendarWidget from '@/Components/shared/DepartmentCalendarWidget';
import { PackageSearch, ClipboardCheck, PackageCheck, Boxes, ArrowRightLeft, Warehouse, Package, ChevronRight, ShoppingCart, FileText } from 'lucide-react';

// v1.11.7 (Bahasa Indonesia Standardization, Part 4) -- hrefs unchanged.
const LOGISTICS_MODULES = [
    { icon: PackageSearch, title: 'Permintaan Material', description: 'Alur permintaan & persetujuan.', href: 'material-requests.index' },
    { icon: PackageCheck, title: 'Penerimaan Barang', description: 'Barang masuk sesuai PO.', href: 'goods-receipts.index' },
    { icon: Warehouse, title: 'Stok Gudang', description: 'Level stok per gudang.', href: 'stock.index' },
    { icon: ArrowRightLeft, title: 'Pergerakan Stok', description: 'Catatan keluar, transfer, penyesuaian.', href: 'stock.movements' },
    { icon: Package, title: 'Data Master Barang', description: 'Katalog barang/material.', href: 'items.index' },
];

const FLOW_STAGES = [
    { key: 'material_requests', label: 'Permintaan Material', icon: PackageSearch },
    { key: 'procurement', label: 'Pengadaan', icon: FileText },
    { key: 'purchase_orders', label: 'Pesanan Pembelian', icon: ShoppingCart },
    { key: 'goods_receipt', label: 'Penerimaan Barang', icon: PackageCheck },
    { key: 'warehouse_stock', label: 'Gudang', icon: Warehouse },
];

/**
 * Logistics/PPIC Dashboard (v1.10.0, redesigned v1.11.5 -- Dashboard UX
 * Completion, Phase 5). New Material Flow strip visualizes the real
 * pipeline-stage counts from LogisticsDashboardController's `materialFlow`
 * prop (Material Request -> Procurement -> Purchase Order -> Goods
 * Receipt -> Warehouse), so PPIC can see at a glance where material is
 * piling up -- no fabricated workflow data, every number is a real,
 * already-proven-correct count.
 */
export default function LogisticsDashboard({
    pendingMaterialRequests, waitingApprovals, goodsReceiptsThisMonth, materialRequestsByStatus, recentGoodsReceipts,
    lowStockCount, recentStockMovements, materialFlow, departmentCalendar,
}) {
    const statusEntries = Object.entries(materialRequestsByStatus || {});

    return (
        <AuthenticatedLayout>
            <Head title="Ringkasan Logistik / PPIC" />
            <DashboardShell title="Ringkasan Logistik / PPIC" subtitle="Tampilan operasional alur material.">
                {/* LEVEL 1 -- compact KPI strip */}
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <StatCard icon={PackageSearch} value={pendingMaterialRequests} label="Permintaan Material Tertunda" href={route('material-requests.index', { status: 'submitted' })} />
                    <StatCard icon={ClipboardCheck} value={waitingApprovals} label="Menunggu Persetujuan" accent={waitingApprovals > 0 ? 'amber' : 'green'} href={route('work-center.index')} />
                    <StatCard icon={PackageCheck} value={goodsReceiptsThisMonth} label="Diterima Bulan Ini" href={route('goods-receipts.index')} />
                    <StatCard icon={Boxes} value={lowStockCount} label="Barang Stok Menipis" accent={lowStockCount > 0 ? 'red' : 'green'} href={route('stock.index', { low_stock: 1 })} />
                </div>

                {/* LEVEL 2 -- Material Flow pipeline */}
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm">Alur Material</CardTitle>
                        <CardDescription>Item terbuka per tahap, saat ini</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="flex flex-wrap items-stretch gap-1">
                            {FLOW_STAGES.map((stage, i) => (
                                <div key={stage.key} className="flex items-center gap-1">
                                    <div className="flex min-w-[110px] flex-col items-center gap-1 rounded-lg border border-graphite-100 px-3 py-2.5 dark:border-slate-800">
                                        <stage.icon className="h-4 w-4 text-graphite-400" />
                                        <span className="text-lg font-bold text-graphite-900 dark:text-slate-50">{materialFlow?.[stage.key] ?? 0}</span>
                                        <span className="text-center text-[11px] leading-tight text-graphite-500">{stage.label}</span>
                                    </div>
                                    {i < FLOW_STAGES.length - 1 && <ChevronRight className="h-4 w-4 shrink-0 text-graphite-300" />}
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>

                {/* LEVEL 3 -- module shortcuts */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    {LOGISTICS_MODULES.map((m) => <ModuleCard key={m.title} {...m} />)}
                </div>

                {/* LEVEL 4 -- status breakdown + recent receipts */}
                <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                    <Card>
                        <CardHeader className="pb-2"><CardTitle className="text-sm">Permintaan Material per Status</CardTitle></CardHeader>
                        <CardContent>
                            <ActivityList
                                items={statusEntries}
                                getKey={([status]) => status}
                                getHref={() => null}
                                emptyIcon={PackageSearch}
                                emptyTitle="Belum ada permintaan material"
                                renderItem={([status, count]) => (
                                    <div className="flex items-center justify-between py-2 text-sm">
                                        <span className="capitalize text-graphite-700 dark:text-slate-200">{status}</span>
                                        <span className="font-semibold text-graphite-800 dark:text-slate-100">{count}</span>
                                    </div>
                                )}
                            />
                            <Link href={route('material-requests.index')} className="mt-2 inline-block text-xs font-medium text-brand-600 hover:underline">Lihat semua</Link>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2"><CardTitle className="text-sm">Penerimaan Barang Terbaru</CardTitle></CardHeader>
                        <CardContent>
                            <ActivityList
                                items={recentGoodsReceipts}
                                getHref={(gr) => route('goods-receipts.show', gr.id)}
                                emptyIcon={PackageCheck}
                                emptyTitle="Belum ada penerimaan barang"
                                renderItem={(gr) => (
                                    <div className="flex items-center justify-between gap-2 py-2 text-sm">
                                        <span className="font-medium text-graphite-700 dark:text-slate-200">{gr.receipt_number}</span>
                                        <span className="text-xs text-graphite-400">{gr.material_request?.request_number || '-'}</span>
                                        <span className="text-xs text-graphite-400">{new Date(gr.received_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short' })}</span>
                                    </div>
                                )}
                            />
                            <Link href={route('goods-receipts.index')} className="mt-2 inline-block text-xs font-medium text-brand-600 hover:underline">Lihat semua</Link>
                        </CardContent>
                    </Card>
                </div>

                {/* LEVEL 5 -- recent movements + calendar */}
                <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                    <Card>
                        <CardHeader className="flex flex-row items-center gap-2 pb-2"><ArrowRightLeft className="h-4 w-4 text-graphite-400" /><CardTitle className="text-sm">Pergerakan Stok Terbaru</CardTitle></CardHeader>
                        <CardContent>
                            <ActivityList
                                items={recentStockMovements}
                                getHref={() => null}
                                emptyIcon={Boxes}
                                emptyTitle="Belum ada pergerakan stok"
                                renderItem={(m) => (
                                    <div className="flex items-center justify-between py-2 text-sm">
                                        <span className="min-w-0 flex-1 truncate font-medium text-graphite-700 dark:text-slate-200">{m.item?.name} -- {m.warehouse?.name}</span>
                                        <span className="capitalize text-xs text-graphite-400">{m.type.replace('_', ' ')}</span>
                                        <span className="text-xs text-graphite-400">{m.quantity}</span>
                                    </div>
                                )}
                            />
                            <Link href={route('stock.movements')} className="mt-2 inline-block text-xs font-medium text-brand-600 hover:underline">Lihat semua</Link>
                        </CardContent>
                    </Card>

                    <DepartmentCalendarWidget events={departmentCalendar} title="Kalender Logistik" description="Jadwal stok & pengiriman, 3 minggu ke depan" />
                </div>
            </DashboardShell>
        </AuthenticatedLayout>
    );
}
