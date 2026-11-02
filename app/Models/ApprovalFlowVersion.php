<?php

namespace App\Models;

use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * v2.141.0 -- A PUBLISHED ROUTE, FROZEN.
 *
 * An immutable copy of a flow's steps at the moment it was published.
 * Approvals pin the version they started on (approvals.approval_flow_version_id),
 * so editing or removing the flow afterwards changes only requests that
 * start afterwards. Like an audit row, a version is never updated or
 * deleted by the application.
 */
class ApprovalFlowVersion extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $builder->where('approval_flow_versions.tenant_id', app(CurrentTenant::class)->id() ?? -1);
        });

        static::updating(fn () => throw new LogicException('A published approval flow version cannot be changed.'));
        static::deleting(fn () => throw new LogicException('A published approval flow version cannot be deleted.'));
    }

    /** The version's steps as (unsaved) step models, so matchesApprover() and mode read as they always did. */
    public function stepModels(?int $stepNumber = null): Collection
    {
        return (new ApprovalFlowStep)->newCollection(
            collect($this->steps ?? [])
                ->when($stepNumber !== null, fn ($steps) => $steps->where('step_number', $stepNumber))
                ->map(fn (array $step) => (new ApprovalFlowStep)->forceFill($step + ['approval_flow_id' => $this->approval_flow_id]))
                ->values()
                ->all()
        );
    }

    /** Rows with sorted keys and normalised scalars, for comparison. */
    public static function canonical(array $steps): array
    {
        return array_map(function ($row) {
            $row = (array) $row;
            ksort($row);

            return array_map(fn ($value) => is_numeric($value) ? (int) $value : $value, $row);
        }, $steps);
    }

    /**
     * The steps of a flow as a version stores them.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function stepsOf(ApprovalFlow $flow): array
    {
        return $flow->steps()->withoutGlobalScopes()->orderBy('step_number')->orderBy('id')->get()
            ->map(fn (ApprovalFlowStep $step) => [
                'step_number' => (int) $step->step_number,
                'kind' => $step->kind ?: ApprovalFlowStep::KIND_APPROVAL,
                'mode' => $step->mode,
                'approver_role' => $step->approver_role,
                'approver_user_id' => $step->approver_user_id ? (int) $step->approver_user_id : null,
                'approver_position_id' => $step->approver_position_id ? (int) $step->approver_position_id : null,
                'escalate_after_hours' => $step->escalate_after_hours ? (int) $step->escalate_after_hours : null,
                'escalate_to_role' => $step->escalate_to_role,
            ])->all();
    }

    /**
     * The version a request starting now is pinned to: the latest one when
     * it still matches the flow's steps, otherwise a new one published now.
     * Called with the flow's tenant current (the engine always is).
     */
    public static function currentFor(ApprovalFlow $flow, ?User $publisher = null): self
    {
        $steps = static::stepsOf($flow);
        $latest = static::withoutGlobalScopes()->where('approval_flow_id', $flow->id)->orderByDesc('version')->first();

        // Compared CANONICALLY: a MySQL JSON column re-orders object keys,
        // so a byte-for-byte comparison published a new version on every
        // request (found on MySQL; SQLite keeps the order).
        if ($latest && static::canonical($latest->steps ?? []) === static::canonical($steps)) {
            return $latest;
        }

        return static::withoutGlobalScopes()->create([
            'approval_flow_id' => $flow->id,
            'tenant_id' => $flow->tenant_id,
            'version' => ($latest?->version ?? 0) + 1,
            'steps' => $steps,
            'published_by' => $publisher?->id,
            'published_at' => now(),
        ]);
    }
}
