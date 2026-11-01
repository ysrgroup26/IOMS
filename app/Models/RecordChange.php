<?php

namespace App\Models;

use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

/**
 * v2.141.0 -- ONE ROW PER CHANGE, AND NONE OF THEM CAN BE CHANGED (A13).
 *
 * Written only by App\Models\Concerns\RecordsChanges. The model refuses an
 * update and a delete outright, so no controller, job or console command
 * that goes through Eloquent can rewrite history; nothing in the routes
 * offers either. Raw `DB::table('record_changes')` writes are outside any
 * model's reach and are a code-review matter (Known Pitfall in
 * CONVENTIONS.md).
 *
 * TENANCY: every row carries the tenant it was written for and is read
 * through a fail-closed tenant scope -- no current tenant, no rows.
 */
class RecordChange extends Model
{
    public const UPDATED_AT = null;

    public const EVENT_CREATED = 'created';

    public const EVENT_UPDATED = 'updated';

    public const EVENT_DELETED = 'deleted';

    public const EVENT_RESTORED = 'restored';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'diff' => 'array',
            'snapshot' => 'array',
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $builder->where('record_changes.tenant_id', app(CurrentTenant::class)->id() ?? -1);
        });

        static::updating(function () {
            throw new LogicException('A record change is append-only and cannot be updated.');
        });

        static::deleting(function () {
            throw new LogicException('A record change is append-only and cannot be deleted.');
        });
    }

    public function scopeFor(Builder $query, Model $subject): Builder
    {
        return $query->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey());
    }

    /**
     * The history of one record as a page shows it: newest first, each
     * change with a readable field name and its values before and after.
     * Read through the tenant scope like every other read.
     */
    public static function historyFor(Model $subject, int $limit = 50): array
    {
        return static::query()->for($subject)->latest('id')->limit($limit)->get()
            ->map(fn (self $row) => [
                'id' => $row->id,
                'event' => $row->event,
                'from_status' => $row->from_status,
                'to_status' => $row->to_status,
                'actor' => $row->actor_name ?? 'System',
                'actor_title' => $row->actor_title,
                'at' => $row->created_at?->toIso8601String(),
                'channel' => $row->context['channel'] ?? null,
                'fields' => collect($row->diff ?? [])
                    ->except(['id', 'company_id', 'status', 'deleted_at'])
                    ->map(fn ($pair, $field) => [
                        'field' => Str::headline(preg_replace('/_id$/', '', $field)),
                        'before' => self::presentValue($pair[0] ?? null),
                        'after' => self::presentValue($pair[1] ?? null),
                    ])->values()->all(),
            ])->all();
    }

    private static function presentValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return Str::limit((string) $value, 160);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
