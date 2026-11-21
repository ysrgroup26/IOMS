<?php

namespace App\Http\Controllers;

use App\Services\AttendanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * v2.146.0 -- a worker's own check-in / check-out from My Work.
 *
 * Route prefix `my-attendance` belongs to the People / HRD workspace for
 * plan entitlement (Business), and is open to every department and to My
 * Work accounts, because whoever works on site checks in. The time is the
 * server's; the location is read once, at the moment of pressing the
 * button, and only when the company requires it.
 */
class MyAttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $attendance) {}

    public function checkIn(Request $request): RedirectResponse
    {
        [$lat, $lng] = $this->position($request);
        $record = $this->attendance->checkIn($request->user(), $lat, $lng);

        return back()->with('success', 'Absen masuk tercatat pukul '
            .$record->check_in_at->copy()->setTimezone($this->attendance->tz())->format('H.i').'.');
    }

    public function checkOut(Request $request): RedirectResponse
    {
        [$lat, $lng] = $this->position($request);
        $record = $this->attendance->checkOut($request->user(), $lat, $lng);
        $h = intdiv((int) $record->worked_minutes, 60);
        $m = (int) $record->worked_minutes % 60;

        return back()->with('success', "Absen pulang tercatat. Jam kerja hari ini {$h} jam {$m} menit."
            .($record->overtime_minutes > 0 ? ' Lembur menunggu persetujuan supervisor.' : ''));
    }

    private function position(Request $request): array
    {
        $data = $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        return [
            isset($data['latitude']) ? (float) $data['latitude'] : null,
            isset($data['longitude']) ? (float) $data['longitude'] : null,
        ];
    }
}
