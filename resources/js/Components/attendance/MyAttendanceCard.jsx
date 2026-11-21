import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Clock, LogIn, LogOut, MapPin } from 'lucide-react';
import { Button } from '@/Components/ui/button';

/**
 * v2.146.0 -- the worker's own check-in / check-out on My Work.
 *
 * The time is the server's, never the phone's. Location is read ONCE, at
 * the moment the button is pressed, and only when the company requires it
 * (Attendance Settings); nothing tracks the person in between.
 */
function hoursText(minutes) {
    if (minutes === null || minutes === undefined) return null;
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return `${h} jam ${m} menit`;
}

const STATUS_LABEL = {
    present: 'Present',
    late: 'Late',
    sick: 'Sick',
    permit: 'Permit',
    absent: 'Absent',
    leave: 'Leave',
};

export default function MyAttendanceCard({ attendance }) {
    const { errors } = usePage().props;
    const [busy, setBusy] = useState(false);
    const [locError, setLocError] = useState(null);

    if (!attendance) return null;

    function send(routeName, coords) {
        router.post(route(routeName), coords ?? {}, {
            preserveScroll: true,
            onFinish: () => setBusy(false),
        });
    }

    function press(routeName) {
        setBusy(true);
        setLocError(null);
        if (!attendance.requires_location) {
            send(routeName);
            return;
        }
        if (!navigator.geolocation) {
            setBusy(false);
            setLocError('Perangkat ini tidak bisa membaca lokasi. Minta foreman mengabsenkan Anda lewat absen regu.');
            return;
        }
        navigator.geolocation.getCurrentPosition(
            (pos) => send(routeName, { latitude: pos.coords.latitude, longitude: pos.coords.longitude }),
            () => {
                setBusy(false);
                setLocError('Lokasi belum diizinkan. Izinkan akses lokasi untuk IOMS di browser, lalu tekan tombol lagi.');
            },
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
        );
    }

    const shift = attendance.shift;

    return (
        <section aria-labelledby="my-attendance" className="surface mb-5 rounded-xl p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 id="my-attendance" className="flex items-center gap-1.5 text-[13px] font-bold uppercase tracking-wide text-graphite-500">
                        <Clock className="h-3.5 w-3.5" /> Attendance
                    </h2>
                    <p className="mt-1 text-sm font-semibold text-navy-900">
                        {shift ? `${shift.name} · ${shift.start} sampai ${shift.end}` : 'Tidak ada shift terjadwal hari ini'}
                    </p>
                    {attendance.site_name && (
                        <p className="flex items-center gap-1 text-xs text-graphite-500"><MapPin className="h-3 w-3" /> {attendance.site_name}</p>
                    )}
                    <p className="mt-1 text-xs text-graphite-500">
                        {attendance.check_in ? `Masuk ${attendance.check_in}` : 'Belum absen masuk'}
                        {attendance.check_out ? ` · Pulang ${attendance.check_out}` : ''}
                        {attendance.worked_minutes ? ` · ${hoursText(attendance.worked_minutes)}` : ''}
                        {attendance.status ? ` · ${STATUS_LABEL[attendance.status] ?? attendance.status}` : ''}
                    </p>
                </div>
                <div className="flex gap-2">
                    {attendance.can_check_in && (
                        <Button size="lg" disabled={busy} onClick={() => press('my-attendance.check-in')}>
                            <LogIn className="h-4 w-4" /> Check In
                        </Button>
                    )}
                    {attendance.can_check_out && (
                        <Button size="lg" variant="outline" disabled={busy} onClick={() => press('my-attendance.check-out')}>
                            <LogOut className="h-4 w-4" /> Check Out
                        </Button>
                    )}
                </div>
            </div>
            {attendance.requires_location && (attendance.can_check_in || attendance.can_check_out) && (
                <p className="mt-2 text-[11px] text-graphite-400">Lokasi dibaca sekali saat tombol ditekan untuk memastikan Anda di area kerja.</p>
            )}
            {(locError || errors?.attendance) && (
                <p role="alert" className="mt-2 rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger">{locError || errors.attendance}</p>
            )}
        </section>
    );
}
