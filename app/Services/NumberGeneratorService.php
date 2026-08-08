<?php

namespace App\Services;

use App\Models\NumberingFormat;
use App\Models\NumberingSequence;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;

/**
 * Milestone 3 (Numbering Engine). Single, reusable, concurrency-safe
 * replacement for the six near-identical `generate*Number()` methods
 * that used to live on MaterialRequest, Incident, LeaveRequest,
 * GoodsReceipt, PpeReplacementRequest, and TaskService -- each did an
 * unlocked `ORDER BY ... DESC LIMIT 1` read-then-write, a real race
 * condition under concurrent requests. This locks a single counter row
 * per module+period inside a DB transaction, so two concurrent requests
 * for the same module always get two different numbers.
 *
 * Every module's DEFAULT format below reproduces exactly what that
 * module's old hardcoded method already produced (same prefix, same
 * `{PREFIX}-{YEAR}-{00001}` shape, same yearly reset) -- generating a
 * number today looks identical to before this engine existed, unless a
 * Company Admin explicitly edits the format later (Task #57).
 */
class NumberGeneratorService
{
    public function __construct(private readonly CurrentTenant $currentTenant) {}

    /**
     * Canonical list of module keys this engine knows about, and the
     * default format each one had BEFORE this engine existed. Adding a
     * new numbered module = one new entry here (or a genuinely custom
     * NumberingFormat row) -- never a new bespoke `generate*Number()`
     * method on the model.
     */
    public const DEFAULTS = [
        'material_request' => ['prefix' => 'MR', 'pattern' => '{PREFIX}-{YEAR}-{SEQ}', 'seq_padding' => 5, 'reset_period' => 'yearly'],
        'incident' => ['prefix' => 'INC', 'pattern' => '{PREFIX}-{YEAR}-{SEQ}', 'seq_padding' => 5, 'reset_period' => 'yearly'],
        'leave_request' => ['prefix' => 'LR', 'pattern' => '{PREFIX}-{YEAR}-{SEQ}', 'seq_padding' => 5, 'reset_period' => 'yearly'],
        'goods_receipt' => ['prefix' => 'GR', 'pattern' => '{PREFIX}-{YEAR}-{SEQ}', 'seq_padding' => 5, 'reset_period' => 'yearly'],
        'ppe_replacement_request' => ['prefix' => 'PRR', 'pattern' => '{PREFIX}-{YEAR}-{SEQ}', 'seq_padding' => 5, 'reset_period' => 'yearly'],
        'task' => ['prefix' => 'TSK', 'pattern' => '{PREFIX}-{YEAR}-{SEQ}', 'seq_padding' => 5, 'reset_period' => 'yearly'],
        // New in Milestone 3 -- Milestone previously had no numbering at all.
        'milestone' => ['prefix' => 'MS', 'pattern' => '{PREFIX}-{YEAR}-{SEQ}', 'seq_padding' => 5, 'reset_period' => 'yearly'],
    ];

    /**
     * @param  string  $moduleKey  one of self::DEFAULTS' keys (or a
     *                             genuinely new one, as long as a
     *                             NumberingFormat row exists for it)
     * @param  int|null  $companyId  used to look up a company-specific
     *                               format override; sequence SCOPE stays
     *                               global (shared across companies) for
     *                               now regardless -- see the migration's
     *                               doc comment for why.
     */
    public function generate(string $moduleKey, ?int $companyId = null): string
    {
        $format = $this->resolveFormat($moduleKey, $companyId);

        $periodKey = match ($format->reset_period) {
            'monthly' => now()->format('Y-m'),
            'never' => 'ALL',
            default => now()->format('Y'),
        };

        $sequence = $this->nextSequence($moduleKey, $periodKey);

        return strtr($format->pattern, [
            '{PREFIX}' => $format->prefix,
            '{YEAR}' => now()->format('Y'),
            '{MONTH}' => now()->format('m'),
            '{SEQ}' => str_pad((string) $sequence, $format->seq_padding, '0', STR_PAD_LEFT),
        ]);
    }

    /**
     * Resolution order: (1) a company-specific override, (2) this
     * TENANT's own default (company_id null, tenant_id set -- what a
     * Company Admin edits from Settings > Numbering), (3) the
     * platform-wide fallback (both null), created from self::DEFAULTS on
     * first use. Milestone 3 (Task #62): tenant_id was added to
     * `numbering_formats` after discovering the tenant-wide default row
     * was actually being shared across EVERY tenant on the platform --
     * see the adding migration's own doc comment.
     */
    private function resolveFormat(string $moduleKey, ?int $companyId): NumberingFormat
    {
        if ($companyId) {
            $override = NumberingFormat::where('company_id', $companyId)->where('module_key', $moduleKey)->first();
            if ($override) {
                return $override;
            }
        }

        $tenantId = $this->currentTenant->id();

        if ($tenantId) {
            $tenantDefault = NumberingFormat::whereNull('company_id')->where('tenant_id', $tenantId)->where('module_key', $moduleKey)->first();
            if ($tenantDefault) {
                return $tenantDefault;
            }
        }

        $defaults = self::DEFAULTS[$moduleKey] ?? ['prefix' => strtoupper(substr($moduleKey, 0, 3)), 'pattern' => '{PREFIX}-{YEAR}-{SEQ}', 'seq_padding' => 5, 'reset_period' => 'yearly'];

        if ($tenantId) {
            // No tenant customization exists yet -- create ONE for this
            // tenant (not the shared platform-null row), so editing it
            // later from Settings never accidentally affects another
            // tenant.
            return NumberingFormat::firstOrCreate(
                ['company_id' => null, 'tenant_id' => $tenantId, 'module_key' => $moduleKey],
                $defaults
            );
        }

        return NumberingFormat::firstOrCreate(
            ['company_id' => null, 'tenant_id' => null, 'module_key' => $moduleKey],
            $defaults
        );
    }

    /**
     * Locks (or creates, then locks) the one counter row for this
     * module+period and atomically increments it. Sequence scope is
     * intentionally global (company_id always NULL here) -- see the
     * migration's doc comment.
     */
    private function nextSequence(string $moduleKey, string $periodKey): int
    {
        return DB::transaction(function () use ($moduleKey, $periodKey) {
            NumberingSequence::firstOrCreate(
                ['company_id' => null, 'module_key' => $moduleKey, 'period_key' => $periodKey],
                ['last_number' => 0]
            );

            $row = NumberingSequence::where('company_id', null)
                ->where('module_key', $moduleKey)
                ->where('period_key', $periodKey)
                ->lockForUpdate()
                ->first();

            $row->increment('last_number');

            return $row->last_number;
        });
    }
}
