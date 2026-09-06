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
        // Milestone 4, Workstream B1 -- Safety Observation. HSE-OBS prefix
        // (not just OBS) so it reads unambiguously as HSE's own module
        // number series, matching the spec's own numbering example.
        'safety_observation' => ['prefix' => 'HSE-OBS', 'pattern' => '{PREFIX}-{YEAR}-{SEQ}', 'seq_padding' => 5, 'reset_period' => 'yearly'],
        // Milestone 4, Workstream B4/B5/B6/B7/B8.
        'risk_assessment' => ['prefix' => 'HIRADC', 'pattern' => '{PREFIX}-{YEAR}-{SEQ}', 'seq_padding' => 5, 'reset_period' => 'yearly'],
        'jsa' => ['prefix' => 'JSA', 'pattern' => '{PREFIX}-{YEAR}-{SEQ}', 'seq_padding' => 5, 'reset_period' => 'yearly'],
        'permit_to_work' => ['prefix' => 'PTW', 'pattern' => '{PREFIX}-{YEAR}-{SEQ}', 'seq_padding' => 5, 'reset_period' => 'yearly'],
        'tbm' => ['prefix' => 'TBM', 'pattern' => '{PREFIX}-{YEAR}-{SEQ}', 'seq_padding' => 5, 'reset_period' => 'yearly'],
        'hse_inspection' => ['prefix' => 'HSE-INS', 'pattern' => '{PREFIX}-{YEAR}-{SEQ}', 'seq_padding' => 5, 'reset_period' => 'yearly'],
        'loto' => ['prefix' => 'LOTO', 'pattern' => '{PREFIX}-{YEAR}-{SEQ}', 'seq_padding' => 5, 'reset_period' => 'yearly'],
        // v1.11.4, HSE Waste Management.
        'waste_record' => ['prefix' => 'HSE-WST', 'pattern' => '{PREFIX}-{YEAR}-{SEQ}', 'seq_padding' => 5, 'reset_period' => 'yearly'],
        // Milestone 4, Workstream C (Procurement). Prefix-only defaults --
        // a Company Admin can edit these later from Settings > Numbering,
        // same as every other module. Never hard-coded to look like a
        // specific tenant's own numbering scheme.
        'vendor' => ['prefix' => 'VEN', 'pattern' => '{PREFIX}-{SEQ}', 'seq_padding' => 4, 'reset_period' => 'never'],
        'purchase_requisition' => ['prefix' => 'PR-PROC', 'pattern' => '{PREFIX}-{YEAR}-{SEQ}', 'seq_padding' => 5, 'reset_period' => 'yearly'],
        'rfq' => ['prefix' => 'RFQ-PROC', 'pattern' => '{PREFIX}-{YEAR}-{SEQ}', 'seq_padding' => 5, 'reset_period' => 'yearly'],
        'purchase_order' => ['prefix' => 'PO-PROC', 'pattern' => '{PREFIX}-{YEAR}-{SEQ}', 'seq_padding' => 5, 'reset_period' => 'yearly'],
        // Milestone 4, Acceleration (Material & Asset / Maintenance /
        // Contractor / Visitor / Document Control).
        'item' => ['prefix' => 'ITM', 'pattern' => '{PREFIX}-{SEQ}', 'seq_padding' => 5, 'reset_period' => 'never'],
        'stock_movement' => ['prefix' => 'SM', 'pattern' => '{PREFIX}-{YEAR}-{SEQ}', 'seq_padding' => 6, 'reset_period' => 'yearly'],
        'asset' => ['prefix' => 'AST', 'pattern' => '{PREFIX}-{SEQ}', 'seq_padding' => 5, 'reset_period' => 'never'],
        'maintenance_request' => ['prefix' => 'MTR', 'pattern' => '{PREFIX}-{YEAR}-{SEQ}', 'seq_padding' => 5, 'reset_period' => 'yearly'],
        'work_order' => ['prefix' => 'WO', 'pattern' => '{PREFIX}-{YEAR}-{SEQ}', 'seq_padding' => 5, 'reset_period' => 'yearly'],
        'inspection_request' => ['prefix' => 'QC-INS', 'pattern' => '{PREFIX}-{YEAR}-{SEQ}', 'seq_padding' => 5, 'reset_period' => 'yearly'],
        'ncr' => ['prefix' => 'NCR', 'pattern' => '{PREFIX}-{YEAR}-{SEQ}', 'seq_padding' => 5, 'reset_period' => 'yearly'],
        'contractor' => ['prefix' => 'CTR', 'pattern' => '{PREFIX}-{SEQ}', 'seq_padding' => 4, 'reset_period' => 'never'],
        'visitor' => ['prefix' => 'VIS', 'pattern' => '{PREFIX}-{YEAR}-{SEQ}', 'seq_padding' => 5, 'reset_period' => 'yearly'],
        'controlled_document' => ['prefix' => 'DOC', 'pattern' => '{PREFIX}-{YEAR}-{SEQ}', 'seq_padding' => 5, 'reset_period' => 'yearly'],
    ];

    /**
     * @param  string  $moduleKey  one of self::DEFAULTS' keys (or a
     *                             genuinely new one, as long as a
     *                             NumberingFormat row exists for it)
     * @param  int|null  $companyId  used to look up a company-specific
     *                               format override. Sequence scope is per
     *                               TENANT as of v2.41.0; it remains shared
     *                               across the companies within one tenant,
     *                               which is deliberate -- `company_id` is
     *                               still reserved for a future
     *                               per-company series (docs/ADR/025).
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
     * Locks (or creates, then locks) this TENANT's counter row for the
     * module+period and atomically increments it.
     *
     * v2.41.0 -- the counter is now per tenant. It was shared across every
     * tenant on the platform until this release: `numbering_formats` got a
     * `tenant_id` back in Milestone 3, but the sequence never did, so each
     * customer saw gaps in its own document numbers wherever another
     * customer consumed the shared counter. See the migration
     * 2026_09_07_100210 for the defect, why the backfill seeds from the
     * shared high-water mark rather than parsing 29 modules' number
     * formats, and why a per-tenant counter cannot re-issue an existing
     * number.
     *
     * `tenant_scope` / `company_scope` (see docs/ADR/025) are the real,
     * always-NOT-NULL columns the uniqueness constraint indexes, because
     * every SQL engine treats each NULL in a unique index as distinct --
     * a nullable id inside the key would not prevent duplicate rows.
     * `firstOrCreate`'s match array MUST include both, or two concurrent
     * requests can each pass the "no matching row yet" check and race to
     * insert: precisely the bug this method exists to close.
     *
     * An unresolved tenant (console, scheduler) falls to scope 0, the
     * platform counter -- it never silently borrows a tenant's series.
     */
    private function nextSequence(string $moduleKey, string $periodKey): int
    {
        $tenantId = $this->currentTenant->id();
        $tenantScope = $tenantId ?? 0;

        return DB::transaction(function () use ($moduleKey, $periodKey, $tenantId, $tenantScope) {
            $match = [
                'tenant_scope' => $tenantScope,
                'company_id' => null,
                'company_scope' => 0,
                'module_key' => $moduleKey,
                'period_key' => $periodKey,
            ];

            // A brand-new tenant starts at 0 legitimately: it has issued no
            // documents, so there is nothing its first number could collide
            // with. Existing tenants were seeded by the migration instead.
            NumberingSequence::firstOrCreate($match, ['tenant_id' => $tenantId, 'last_number' => 0]);

            $row = NumberingSequence::where('tenant_scope', $tenantScope)
                ->where('company_id', null)
                ->where('company_scope', 0)
                ->where('module_key', $moduleKey)
                ->where('period_key', $periodKey)
                ->lockForUpdate()
                ->first();

            $row->increment('last_number');

            return $row->last_number;
        });
    }
}
