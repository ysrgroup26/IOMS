import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DashboardShell from '@/Components/shared/DashboardShell';
import StatCard from '@/Components/shared/StatCard';
import ModuleCard from '@/Components/shared/ModuleCard';
import ActivityList from '@/Components/shared/ActivityList';
import DepartmentCalendarWidget from '@/Components/shared/DepartmentCalendarWidget';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Boxes, AlertTriangle, XCircle, PackageCheck, ArrowRightLeft, Warehouse, Box, ClipboardList } from 'lucide-react';

// v1.11.7 (Bahasa Indonesia Standardization, Part 4).
const HEALTH_BADGES = {
    out_of_stock: { label: 'Stok Habis', className: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300' },
    critical: { label: 'Kritis', className: 'bg-red-50 text-red-600 dark:bg-red-950/50 dark:text-red-400' },
    low: { label: 'Menipis', className: 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300' },
    healthy: { label: 'Sehat', className: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' },
};

function HealthBadge({ status }) {
    const cfg = HEALTH_BADGES[status] || HEALTH_BADGES.healthy;
    return <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${cfg.className}`}>{cfg.label}</span>;
}

const WAREHOUSE_MODULES = [
    { icon: Warehouse, title: 'Data Master Gudang', description: 'Gudang & lokasi penyimpanan.', href: 'warehouses.master' },
    { icon: Box, title: 'Data Master Barang', description: 'Katalog barang & level stok.', href: 'items.index' },
    { icon: Boxes, title: 'Inventaris', description: 'Stok saat ini per gudang.', href: 'stock.index' },
    { icon: PackageCheck, title: 'Penerimaan Barang', description: 'Catatan stok masuk.', href: 'goods-receipts.index' },
    { icon: ArrowRightLeft, title: 'Keluar / Transfer / Penyesuaian', description: 'Pergerakan stok keluar & internal.', href: 'stock.transactions.create' },
    { icon: ClipboardList, title: 'Riwayat Pergerakan', description: 'Log lengkap pergerakan stok.', href: 'stock.movements' },
];

/**
 * Warehouse Overview (v1.11.3.2 -- Priority Pass Part 9). Warehouse was
 * confirmed to have real, substantial backend already (Stock/
 * StockMovement/GoodsReceipt/Item, all reused here -- none duplicated)
 * but no Overview of its own; `warehouses.master` is the warehouse
 * REGISTER/config page, not a dashboard. This is that Overview, built on
 * the shared component set from day one, matching the other priority
 * department dashboards.
 */
export default function WarehouseDashboard({
    totalItemsCount, totalWarehousesCount, lowStockCount, outOfStockCount,
    goodsReceiptsThisMonth, movementsThisMonth, recentReceiving, recentIssuing, lowStockItems, inventoryHealth, departmentCalendar,
}) {
    return (
        <AuthenticatedLayout>
            <Head title="Ringkasan Gudang" />
            <DashboardShell title="Ringkasan Gudang" subtitle="Kesehatan stok, penerimaan, dan pengeluaran.">
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
                    <StatCard icon={Box} value={totalItemsCount} label="Barang Aktif" href={route('items.index')} />
                    <StatCard icon={Warehouse} value={totalWarehousesCount} label="Gudang" href={route('warehouses.master')} />
                    <StatCard icon={AlertTriangle} value={lowStockCount} label="Stok Menipis" accent={lowStockCount > 0 ? 'amber' : 'green'} href={route('stock.index', { low_stock: 1 })} />
                    <StatCard icon={XCircle} value={outOfStockCount} label="Stok Habis" accent={outOfStockCount > 0 ? 'red' : 'green'} href={route('stock.index')} />
                    <StatCard icon={PackageCheck} value={goodsReceiptsThisMonth} label="Diterima Bulan Ini" href={route('goods-receipts.index')} />
                </div>

                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    {WAREHOUSE_MODULES.map((m) => <ModuleCard key={m.title} {...m} />)}
                </div>

                {/* Inventory Health -- compact table, Phase 6 */}
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle>Kesehatan Inventaris</CardTitle>
                        <CardDescription>Stok terendah relatif terhadap minimum, dahulu</CardDescription>
                    </CardHeader>
                    <CardContent className="overflow-x-auto">
                        {(!inventoryHealth || inventoryHealth.length === 0) ? (
                            <p className="py-6 text-center text-sm text-graphite-400">Belum ada catatan stok.</p>
                        ) : (
                            <table className="w-full min-w-[640px] text-sm">
                                <thead>
                                    <tr className="border-b border-graphite-100 text-left text-xs text-graphite-400 dark:border-slate-800">
                                        <th className="py-1.5 font-medium">Barang</th>
                                        <th className="py-1.5 font-medium">Kategori</th>
                                        <th className="py-1.5 font-medium">Lokasi</th>
                                        <th className="py-1.5 font-medium">Stok</th>
                                        <th className="py-1.5 font-medium">Batas Min.</th>
                                        <th className="py-1.5 font-medium">Status</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-graphite-50 dark:divide-slate-800/60">
                                    {inventoryHealth.map((row) => (
                                        <tr key={row.id} className="hover:bg-graphite-25 dark:hover:bg-slate-900/40">
                                            <td className="py-2 pr-2">
                                                <p className="font-medium text-graphite-700 dark:text-slate-200">{row.item_name}</p>
                                                <p className="text-xs text-graphite-400">{row.item_code}</p>
                                            </td>
                                            <td className="py-2 pr-2 capitalize text-graphite-500">{row.category ?? '—'}</td>
                                            <td className="py-2 pr-2 text-graphite-500">{row.location ?? '—'}</td>
                                            <td className="py-2 pr-2 tabular-nums text-graphite-700 dark:text-slate-200">{row.quantity} {row.unit}</td>
                                            <td className="py-2 pr-2 tabular-nums text-graphite-500">{row.min_stock ?? '—'}</td>
                                            <td className="py-2"><HealthBadge status={row.status} /></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                        <Link href={route('stock.index')} className="mt-2 inline-block text-xs font-medium text-brand-600 hover:underline">Lihat seluruh inventaris</Link>
                    </CardContent>
                </Card>

                <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
                    {/* v2.30.0 (Interior UI Transformation, Phase 2, Part
                        10): a real exception -- items below their own
                        min_stock -- should read as visually prominent,
                        not sit in a neutral card identical to "Recent
                        Receiving" next to it. Border/header tint only
                        activates when `lowStockItems` actually has rows;
                        an empty list keeps the plain neutral card. */}
                    <Card className={lowStockItems?.length > 0 ? 'border-amber-200' : undefined}>
                        <CardHeader className={lowStockItems?.length > 0 ? 'rounded-t-[10px] bg-amber-50/60' : undefined}>
                            <CardTitle className={lowStockItems?.length > 0 ? 'text-amber-800' : undefined}>Barang Stok Menipis</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ActivityList
                                items={lowStockItems}
                                emptyIcon={Boxes}
                                emptyTitle="Tidak ada yang di bawah stok minimum"
                                renderItem={(s) => (
                                    <div className="flex items-center justify-between gap-2 py-2 text-sm">
                                        <span className="min-w-0 flex-1 truncate font-medium text-graphite-700 dark:text-slate-200">{s.item?.item_code} -- {s.item?.name}</span>
                                        <span className="shrink-0 text-xs text-graphite-400">{s.warehouse?.name}</span>
                                        <span className="shrink-0 text-xs font-semibold text-amber-600">{s.quantity}/{s.item?.min_stock} {s.item?.unit}</span>
                                    </div>
                                )}
                            />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle>Penerimaan Terbaru</CardTitle></CardHeader>
                        <CardContent>
                            <ActivityList
                                items={recentReceiving}
                                getHref={(g) => route('goods-receipts.show', g.id)}
                                emptyIcon={PackageCheck}
                                emptyTitle="Belum ada penerimaan barang"
                                renderItem={(g) => (
                                    <div className="flex items-center justify-between gap-2 py-2 text-sm">
                                        <span className="min-w-0 flex-1 truncate font-medium text-graphite-700 dark:text-slate-200">{g.receipt_number}</span>
                                        <span className="shrink-0 text-xs text-graphite-400">{g.warehouse?.name}</span>
                                        <span className="shrink-0 text-xs text-graphite-400">{new Date(g.received_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short' })}</span>
                                    </div>
                                )}
                            />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle>Pengeluaran / Transfer Terbaru</CardTitle></CardHeader>
                        <CardContent>
                            <ActivityList
                                items={recentIssuing}
                                emptyIcon={ArrowRightLeft}
                                emptyTitle="Belum ada pengeluaran atau transfer"
                                renderItem={(m) => (
                                    <div className="flex items-center justify-between gap-2 py-2 text-sm">
                                        <span className="min-w-0 flex-1 truncate font-medium text-graphite-700 dark:text-slate-200">{m.item?.name}</span>
                                        <span className="shrink-0 text-xs capitalize text-graphite-400">{m.type.replace('_', ' ')}</span>
                                        <span className="shrink-0 text-xs text-graphite-400">{m.quantity}</span>
                                    </div>
                                )}
                            />
                        </CardContent>
                    </Card>
                </div>

                <DepartmentCalendarWidget events={departmentCalendar} title="Kalender Gudang" description="3 minggu ke depan" />
            </DashboardShell>
        </AuthenticatedLayout>
    );
}
