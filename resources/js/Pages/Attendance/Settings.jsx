import { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { MapPin } from 'lucide-react';

/**
 * v2.146.0 -- Attendance Settings: the location check and the work sites
 * a check-in is measured against. Defaults (owner decision, 21 Nov 2026):
 * location check ON, selfie OFF. The selfie switch is recorded now; photo
 * capture itself ships in a later release.
 */
export default function AttendanceSettings({ sites, companies, settings }) {
    const [req, setReq] = useState(settings.require_location);
    const [selfie, setSelfie] = useState(settings.selfie);
    const form = useForm({ company_id: companies[0]?.id ?? '', name: '', latitude: '', longitude: '', radius_m: 300 });

    const saveSettings = () => router.put(route('attendance.settings.update'), { require_location: req, selfie }, { preserveScroll: true });
    const addSite = (e) => { e.preventDefault(); form.post(route('attendance.sites.store'), { preserveScroll: true, onSuccess: () => form.reset('name', 'latitude', 'longitude') }); };
    const deactivate = (s) => router.delete(route('attendance.sites.destroy', s.id), { preserveScroll: true });

    return (
        <AuthenticatedLayout>
            <Head title="Attendance Settings" />
            <PageHeader title="Attendance Settings" subtitle="Atur pemeriksaan lokasi saat absen dan titik area kerja yang dipakai sebagai acuan.">
                <Button asChild variant="outline"><Link href={route('attendance.index')}>Back to Attendance</Link></Button>
            </PageHeader>

            <section className="surface mb-5 space-y-3 rounded-xl p-4">
                <h2 className="text-sm font-bold">Check-in Rules</h2>
                <label className="flex items-start gap-3 text-sm">
                    <input type="checkbox" className="mt-1 h-4 w-4" checked={req} onChange={(e) => setReq(e.target.checked)} />
                    <span><b>Location check</b><br /><span className="text-graphite-500">Absen mandiri hanya diterima di dalam radius salah satu site aktif. Lokasi dibaca sekali saat tombol ditekan, tidak dilacak sepanjang hari. Bila belum ada site, absen tetap diterima tanpa pemeriksaan lokasi.</span></span>
                </label>
                <label className="flex items-start gap-3 text-sm">
                    <input type="checkbox" className="mt-1 h-4 w-4" checked={selfie} onChange={(e) => setSelfie(e.target.checked)} />
                    <span><b>Selfie on check-in</b><br /><span className="text-graphite-500">Pengaturan disimpan sekarang; pengambilan foto saat absen hadir di rilis berikutnya.</span></span>
                </label>
                <Button onClick={saveSettings}>Save Rules</Button>
            </section>

            <section className="surface space-y-3 rounded-xl p-4">
                <h2 className="text-sm font-bold">Work Sites</h2>
                {sites.length === 0 ? (
                    <p className="text-sm text-graphite-500">Belum ada site. Tambahkan titik yard, dok, atau lokasi proyek beserta radiusnya. Koordinat bisa disalin dari Google Maps (klik kanan pada peta).</p>
                ) : (
                    <ul className="divide-y divide-graphite-100 dark:divide-slate-800">
                        {sites.map((s) => (
                            <li key={s.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                                <span className="flex min-w-0 items-center gap-2"><MapPin className="h-4 w-4 shrink-0 text-brand-600" />
                                    <span><b>{s.name}</b> · {s.latitude}, {s.longitude} · radius {s.radius_m} m {!s.is_active && <span className="text-graphite-400">(nonaktif)</span>}</span>
                                </span>
                                {s.is_active && <Button size="sm" variant="outline" onClick={() => deactivate(s)}>Deactivate</Button>}
                            </li>
                        ))}
                    </ul>
                )}
                <form onSubmit={addSite} className="grid gap-2 sm:grid-cols-6">
                    {companies.length > 1 && (
                        <div className="sm:col-span-2">
                            <Label htmlFor="site-company">Operating Unit</Label>
                            <select id="site-company" className="mt-1 w-full rounded-md border border-graphite-200 bg-white px-2.5 py-2 text-sm dark:border-slate-700 dark:bg-slate-900"
                                value={form.data.company_id} onChange={(e) => form.setData('company_id', e.target.value)}>
                                {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </select>
                        </div>
                    )}
                    <div className="sm:col-span-2"><Label htmlFor="site-name">Site Name</Label><Input id="site-name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="Yard A" /></div>
                    <div><Label htmlFor="site-lat">Latitude</Label><Input id="site-lat" inputMode="decimal" value={form.data.latitude} onChange={(e) => form.setData('latitude', e.target.value)} placeholder="-1.2654" /></div>
                    <div><Label htmlFor="site-lng">Longitude</Label><Input id="site-lng" inputMode="decimal" value={form.data.longitude} onChange={(e) => form.setData('longitude', e.target.value)} placeholder="116.8312" /></div>
                    <div><Label htmlFor="site-radius">Radius (m)</Label><Input id="site-radius" type="number" min={30} max={5000} value={form.data.radius_m} onChange={(e) => form.setData('radius_m', e.target.value)} /></div>
                    <div className="flex items-end sm:col-span-6"><Button type="submit" disabled={form.processing}>Add Site</Button></div>
                    {Object.values(form.errors).length > 0 && <p role="alert" className="text-sm text-danger sm:col-span-6">{Object.values(form.errors)[0]}</p>}
                </form>
            </section>
        </AuthenticatedLayout>
    );
}
