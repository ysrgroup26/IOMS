<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Models\RecordChange;
use App\Support\CurrentTenant;
use App\Support\SystemActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/**
 * v2.141.0 -- A13: WHAT CHANGED, BEFORE AND AFTER, WRITTEN BY THE MODEL.
 *
 * A model that uses this trait gets one RecordChange row per create, update,
 * delete and restore, holding:
 *
 *   diff       { field: [before, after] } for every attribute that changed
 *              (timestamps ignored; secrets redacted, never stored)
 *   status     from_status / to_status when `status` moved -- a workflow
 *              transition reads as one, whichever code path made it
 *   snapshot   the full redacted record at a transition, for models that
 *              declare `protected bool $recordSnapshotOnTransition = true`
 *              (PTW: what the permit said at the moment it was approved)
 *   who/where  actor id, name and job title AT THE TIME (as ActivityLog
 *              does), channel (web / console / queue), route, IP
 *
 * WRITING NEVER BREAKS THE BUSINESS ACTION. The row is written in the same
 * transaction as the change (so a rolled-back change leaves no row), but a
 * failure to write it is reported and swallowed rather than undoing an
 * approval -- the same rule ADR 067 set for notifications. A test pins
 * that rows ARE written; report() makes a failure visible in the logs.
 *
 * Covers: every HasWorkflow model (through HasWorkflow), approval flows and
 * their steps, and the PTW workflow settings. Work Order and Form
 * definitions add it when they are built.
 */
trait RecordsChanges
{
    /** Never stored, whatever a model calls them. */
    private static array $redactedPatterns = ['password', 'secret', 'token', 'api_key', 'access_code', 'pin'];

    private static array $ignoredFields = ['created_at', 'updated_at', 'remember_token'];

    public static function bootRecordsChanges(): void
    {
        static::created(fn (Model $model) => $model->writeRecordChange(RecordChange::EVENT_CREATED));
        static::updated(fn (Model $model) => $model->writeRecordChange(RecordChange::EVENT_UPDATED));
        static::deleted(fn (Model $model) => $model->writeRecordChange(RecordChange::EVENT_DELETED));

        if (method_exists(static::class, 'restored')) {
            static::restored(fn (Model $model) => $model->writeRecordChange(RecordChange::EVENT_RESTORED));
        }
    }

    public function recordChanges()
    {
        return $this->morphMany(RecordChange::class, 'subject')->orderBy('id');
    }

    /** The tenant this record belongs to. A model without its own company overrides this. */
    public function recordChangeTenantId(): ?int
    {
        if (! empty($this->attributes['tenant_id'])) {
            return (int) $this->attributes['tenant_id'];
        }

        if (! empty($this->attributes['company_id'])) {
            $tenantId = Company::withoutGlobalScopes()->whereKey($this->attributes['company_id'])->value('tenant_id');
            if ($tenantId) {
                return (int) $tenantId;
            }
        }

        return app(CurrentTenant::class)->id();
    }

    protected function writeRecordChange(string $event): void
    {
        try {
            $diff = $this->recordChangeDiff($event);

            if ($event === RecordChange::EVENT_UPDATED && $diff === []) {
                return;
            }

            $statusMoved = $event === RecordChange::EVENT_UPDATED && array_key_exists('status', $diff);
            // A system action (SystemActor) has no person behind it.
            $user = SystemActor::active() ? null : auth()->user();

            RecordChange::create([
                'tenant_id' => $this->recordChangeTenantId(),
                'company_id' => $this->attributes['company_id'] ?? null,
                'subject_type' => $this->getMorphClass(),
                'subject_id' => $this->getKey(),
                'event' => $event,
                'from_status' => $statusMoved ? $diff['status'][0] : null,
                'to_status' => $statusMoved ? $diff['status'][1] : ($event === RecordChange::EVENT_CREATED ? ($this->attributes['status'] ?? null) : null),
                'diff' => $diff ?: null,
                'snapshot' => $statusMoved && ($this->recordSnapshotOnTransition ?? false) ? $this->redactedAttributes($this->getAttributes()) : null,
                'actor_id' => $user?->id,
                'actor_name' => $user?->displayName(),
                'actor_title' => $user?->employee?->position?->name,
                'context' => $this->recordChangeContext(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @return array<string, array{0: mixed, 1: mixed}> */
    private function recordChangeDiff(string $event): array
    {
        $diff = [];

        if ($event === RecordChange::EVENT_UPDATED) {
            foreach ($this->getChanges() as $field => $after) {
                if (in_array($field, self::$ignoredFields, true)) {
                    continue;
                }
                $diff[$field] = [$this->getRawOriginal($field), $after];
            }
        } elseif ($event === RecordChange::EVENT_CREATED) {
            foreach ($this->getAttributes() as $field => $value) {
                if (in_array($field, self::$ignoredFields, true) || $value === null) {
                    continue;
                }
                $diff[$field] = [null, $value];
            }
        } elseif ($event === RecordChange::EVENT_DELETED) {
            foreach ($this->getRawOriginal() as $field => $value) {
                if (in_array($field, self::$ignoredFields, true) || $value === null) {
                    continue;
                }
                $diff[$field] = [$value, null];
            }
        }

        foreach ($diff as $field => $pair) {
            if ($this->isRedactedField($field)) {
                $diff[$field] = ['[redacted]', '[redacted]'];
            }
        }

        return $diff;
    }

    private function redactedAttributes(array $attributes): array
    {
        foreach ($attributes as $field => $value) {
            if ($this->isRedactedField($field)) {
                $attributes[$field] = '[redacted]';
            }
        }

        return $attributes;
    }

    private function isRedactedField(string $field): bool
    {
        $field = Str::lower($field);
        foreach (self::$redactedPatterns as $pattern) {
            if ($field === $pattern || Str::contains($field, $pattern) && $pattern !== 'pin') {
                return true;
            }
        }

        return in_array($field, $this->recordChangeRedacted ?? [], true);
    }

    private function recordChangeContext(): array
    {
        if (SystemActor::active()) {
            return ['channel' => 'system'];
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return ['channel' => 'console'];
        }

        $request = request();

        return array_filter([
            'channel' => 'web',
            'route' => $request?->route()?->getName(),
            'ip' => $request?->ip(),
        ]);
    }
}
