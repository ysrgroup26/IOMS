<?php

namespace App\Http\Controllers;

use App\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Milestone 3 (Analytics Framework, Task #64). Thin controller over
 * AnalyticsService -- index() renders the full Analytics page (every
 * visible dataset as a chart), show() returns one dataset as JSON for
 * a single dashboard widget to fetch on demand (used by Dashboard/Index
 * and the department dashboards for their "Analytics" cards).
 */
class AnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsService $analytics) {}

    public function index(Request $request): Response
    {
        $available = $this->analytics->available($this->enabledModuleKeys($request));

        return Inertia::render('Analytics/Index', [
            'available' => $available,
            'datasets' => collect($available)->mapWithKeys(fn ($d) => [$d['key'] => $this->analytics->dataset($d['key'])])->all(),
        ]);
    }

    public function show(Request $request, string $key): JsonResponse
    {
        abort_unless(in_array($key, array_column($this->analytics->available($this->enabledModuleKeys($request)), 'key'), true), 404);

        return response()->json($this->analytics->dataset($key));
    }

    private function enabledModuleKeys(Request $request): array
    {
        $user = $request->user();

        if (! $user?->tenant) {
            return [];
        }

        $grantedKeys = $user->tenant->modules()->pluck('key')->all();
        $stored = json_decode(
            \App\Models\CompanySetting::where('key', 'enabled_modules')->value('value') ?? json_encode($grantedKeys),
            true
        ) ?? $grantedKeys;

        return array_values(array_intersect($stored, $grantedKeys));
    }
}
