<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CorrectiveAction;
use App\Models\EmployeeCompetency;
use App\Models\HseInspection;
use App\Models\Incident;
use App\Models\PermitToWork;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * v2.145.0 -- SAFETY EXECUTIVE DASHBOARD.
 *
 * One screen that answers "how safe were our operations this month?" for
 * directors, HSE managers and site managers. Every number is derived from
 * records the team already keeps in IOMS: Incident (with the reporter's
 * injury classification), Man-Hour logs, Permit to Work, CAPA, Training &
 * Competency and HSE Inspection.
 *
 * Same honesty rule as HseDashboardController::frequencyRates(): when no
 * man-hours exist for a period the rates are null with a reason, never 0.
 * A rate computed from a missing denominator would be quoted as if real.
 *
 * Recordable = fatality + lost time + restricted work + medical treatment
 * (OSHA TRIR, per 200,000 hours). LTIFR = lost time + fatality per
 * 1,000,000 hours, the frequency rate commonly used in Indonesia.
 */
class SafetyExecutiveService
{
    public const RECORDABLE = ['fatality', 'lost_time', 'restricted_work', 'medical_treatment'];

    public const LOST_TIME = ['fatality', 'lost_time'];

    public function __construct(private readonly DashboardStatsService $stats) {}

    /**
     * @param  array<int>  $companyIds  already tenant-resolved
     */
    public function overview(array $companyIds, CarbonInterface $month): array
    {
        $from = Carbon::parse($month)->startOfMonth();
        $to = $from->copy()->endOfMonth()->min(now()->endOfDay());
        $prevFrom = $from->copy()->subMonthNoOverflow()->startOfMonth();
        $prevTo = $prevFrom->copy()->endOfMonth();

        $current = $this->rates($companyIds, $from, $to);
        $previous = $this->rates($companyIds, $prevFrom, $prevTo);

        return [
            'period' => [
                'month' => $from->format('Y-m'),
                'label' => $from->translatedFormat('F Y'),
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'previous_label' => $prevFrom->translatedFormat('F Y'),
            ],
            'lti' => $this->sinceLastLostTime($companyIds),
            'rates' => $current,
            'previous_rates' => $previous,
            'near_miss' => $this->nearMiss($companyIds, $from, $to),
            'trend' => $this->trend($companyIds, $from),
            'pyramid' => $this->pyramid($companyIds, $from),
            'permits' => $this->permits($companyIds),
            'capa' => $this->capa($companyIds, $from, $to),
            'competency' => $this->competency($companyIds),
            'inspections' => $this->inspections($companyIds, $from, $to),
            'units' => $this->units($companyIds, $from, $to),
        ];
    }

    /** Incident query for these companies (null-company incidents are shared, as on the HSE Overview). */
    private function incidents(array $companyIds)
    {
        return Incident::query()->where(fn ($q) => $q->whereIn('company_id', $companyIds)->orWhereNull('company_id'));
    }

    public function rates(array $companyIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $counts = $this->incidents($companyIds)
            ->whereBetween('incident_date', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])
            ->whereNotNull('injury_severity')
            ->selectRaw('injury_severity, count(*) as total')
            ->groupBy('injury_severity')
            ->pluck('total', 'injury_severity');

        $recordable = (int) collect(self::RECORDABLE)->sum(fn ($k) => (int) ($counts[$k] ?? 0));
        $lostTime = (int) collect(self::LOST_TIME)->sum(fn ($k) => (int) ($counts[$k] ?? 0));
        $hours = $this->stats->sumManHours($companyIds, $from->toDateString(), $to->toDateString());
        $available = $hours !== null && $hours > 0;

        return [
            'available' => $available,
            'man_hours' => $hours,
            'recordable' => $recordable,
            'lost_time' => $lostTime,
            'trir' => $available ? round($recordable * 200000 / $hours, 2) : null,
            'ltifr' => $available ? round($lostTime * 1000000 / $hours, 2) : null,
            'unavailable_reason' => $available ? null : 'Isi jam kerja di Man-Hour untuk menghitung TRIR dan LTIFR.',
        ];
    }

    private function sinceLastLostTime(array $companyIds): array
    {
        $last = $this->incidents($companyIds)
            ->whereIn('injury_severity', self::LOST_TIME)
            ->whereNotNull('incident_date')
            ->with('company:id,name')
            ->latest('incident_date')
            ->first(['id', 'incident_date', 'company_id']);

        if (! $last) {
            return ['recorded' => false, 'days' => null, 'last_date' => null, 'unit' => null, 'safe_hours' => null];
        }

        $date = Carbon::parse($last->incident_date)->startOfDay();

        return [
            'recorded' => true,
            'days' => (int) $date->diffInDays(now()->startOfDay()),
            'last_date' => $date->toDateString(),
            'unit' => $last->company?->name,
            // hours worked AFTER the LTI day; null (not 0) when nobody logged man-hours
            'safe_hours' => $this->stats->sumManHours($companyIds, $date->copy()->addDay()->toDateString(), now()->toDateString()),
        ];
    }

    private function nearMiss(array $companyIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $range = [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()];
        $nearMiss = (clone $this->incidents($companyIds))->where('category', 'near_miss')->whereBetween('incident_date', $range)->count();
        $other = (clone $this->incidents($companyIds))->where('category', '!=', 'near_miss')->whereBetween('incident_date', $range)->count();

        return [
            'count' => $nearMiss,
            'incidents' => $other,
            // "1 incident : N near misses"; null when there is no incident to divide by
            'ratio' => $other > 0 ? round($nearMiss / $other, 1) : null,
        ];
    }

    private function months(CarbonInterface $end): array
    {
        $start = Carbon::parse($end)->startOfMonth()->subMonths(11);

        return collect(range(0, 11))->map(fn ($i) => $start->copy()->addMonths($i)->format('Y-m'))->all();
    }

    private function trend(array $companyIds, CarbonInterface $end): array
    {
        $months = $this->months($end);
        $from = Carbon::parse($months[0].'-01')->startOfDay();
        $to = Carbon::parse($end)->endOfMonth()->endOfDay();

        $rows = $this->incidents($companyIds)
            ->whereBetween('incident_date', [$from, $to])
            ->get(['incident_date', 'category']);

        $incidents = array_fill_keys($months, 0);
        $nearMiss = array_fill_keys($months, 0);
        foreach ($rows as $row) {
            $key = Carbon::parse($row->incident_date)->format('Y-m');
            if ($row->category === 'near_miss') {
                $nearMiss[$key] = ($nearMiss[$key] ?? 0) + 1;
            } else {
                $incidents[$key] = ($incidents[$key] ?? 0) + 1;
            }
        }

        return [
            'labels' => $months,
            'incidents' => array_values(array_intersect_key($incidents, array_flip($months))),
            'near_miss' => array_values(array_intersect_key($nearMiss, array_flip($months))),
        ];
    }

    private function pyramid(array $companyIds, CarbonInterface $end): array
    {
        $months = $this->months($end);
        $range = [Carbon::parse($months[0].'-01')->startOfDay(), Carbon::parse($end)->endOfMonth()->endOfDay()];

        $counts = $this->incidents($companyIds)
            ->whereBetween('incident_date', $range)
            ->whereNotNull('injury_severity')
            ->selectRaw('injury_severity, count(*) as total')
            ->groupBy('injury_severity')
            ->pluck('total', 'injury_severity');
        $of = fn (string $k) => (int) ($counts[$k] ?? 0);

        return [
            ['key' => 'fatality', 'label' => 'Fatality', 'value' => $of('fatality')],
            ['key' => 'lost_time', 'label' => 'Lost Time', 'value' => $of('lost_time')],
            ['key' => 'restricted_work', 'label' => 'Restricted Work', 'value' => $of('restricted_work')],
            ['key' => 'medical_treatment', 'label' => 'Medical Treatment', 'value' => $of('medical_treatment')],
            ['key' => 'first_aid', 'label' => 'First Aid', 'value' => $of('first_aid')],
            ['key' => 'near_miss', 'label' => 'Near Miss', 'value' => (clone $this->incidents($companyIds))
                ->where('category', 'near_miss')->whereBetween('incident_date', $range)->count()],
        ];
    }

    private function permits(array $companyIds): array
    {
        $base = fn () => PermitToWork::query()->whereIn('company_id', $companyIds);

        return [
            'active' => $base()->where('status', PermitToWork::STATUS_ACTIVE)->count(),
            'awaiting' => $base()->whereIn('status', [PermitToWork::STATUS_SUBMITTED, PermitToWork::STATUS_VERIFIED])->count(),
            // past their end time but never closed out -- the case a site manager must chase today
            'overrun' => $base()->whereIn('status', [PermitToWork::STATUS_APPROVED, PermitToWork::STATUS_ACTIVE])
                ->whereNotNull('end_datetime')->where('end_datetime', '<', now())->count(),
        ];
    }

    private function capa(array $companyIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $base = fn () => CorrectiveAction::query()->whereIn('company_id', $companyIds);
        $done = [CorrectiveAction::STATUS_VERIFIED, CorrectiveAction::STATUS_CANCELLED];

        return [
            'open' => $base()->whereNotIn('status', $done)->count(),
            'overdue' => $base()->whereNotIn('status', $done)->whereNotNull('due_date')->whereDate('due_date', '<', now())->count(),
            'closed_in_period' => $base()->where('status', CorrectiveAction::STATUS_VERIFIED)
                ->whereBetween('verified_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->count(),
        ];
    }

    private function competency(array $companyIds): array
    {
        $base = fn () => EmployeeCompetency::query()->whereHas('employee', fn ($q) => $q->whereIn('company_id', $companyIds));

        return [
            'expiring_soon' => $base()->effectiveStatus('expiring_soon')->count(),
            'expired' => $base()->effectiveStatus('expired')->count(),
        ];
    }

    private function inspections(array $companyIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = HseInspection::query()->whereIn('company_id', $companyIds)
            ->whereBetween('inspection_date', [$from->toDateString(), $to->toDateString()]);

        return [
            'count' => (clone $rows)->count(),
            'failed' => (clone $rows)->where('overall_result', 'fail')->count(),
        ];
    }

    private function units(array $companyIds, CarbonInterface $from, CarbonInterface $to): array
    {
        if (count($companyIds) < 2) {
            return [];
        }
        $range = [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()];

        return Company::query()->whereIn('id', $companyIds)->orderBy('name')->get(['id', 'name'])
            ->map(function (Company $c) use ($from, $to, $range) {
                $r = $this->rates([$c->id], $from, $to);
                $own = fn () => Incident::query()->where('company_id', $c->id)->whereBetween('incident_date', $range);

                return [
                    'id' => $c->id,
                    'name' => $c->name,
                    'man_hours' => $r['man_hours'],
                    'incidents' => $own()->where('category', '!=', 'near_miss')->count(),
                    'lost_time' => $r['lost_time'],
                    'trir' => $r['trir'],
                    'near_miss' => $own()->where('category', 'near_miss')->count(),
                    'capa_overdue' => CorrectiveAction::query()->where('company_id', $c->id)
                        ->whereNotIn('status', [CorrectiveAction::STATUS_VERIFIED, CorrectiveAction::STATUS_CANCELLED])
                        ->whereNotNull('due_date')->whereDate('due_date', '<', now())->count(),
                ];
            })->values()->all();
    }
}
