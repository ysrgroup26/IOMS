<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\TenantRegistration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * ADR 087 -- DELETING AN ORGANIZATION REMOVES EXACTLY ITS ROWS, OR NOTHING.
 *
 * IOMS's schema protects company data with RESTRICT foreign keys (41 of them on
 * `company_id` alone), so an organization cannot be removed by deleting its
 * tenant row. This service walks the REAL foreign-key graph of the running
 * database (Schema::getForeignKeys), so a table added next year is covered
 * without anybody remembering to list it here:
 *
 *  1. SEEDS: the tenant row; every row whose `tenant_id` is the tenant -- even
 *     where that FK is SET NULL, because a row that names the organization is
 *     the organization's (its registrations, its support tickets); every row
 *     whose `company_id` is one of its companies (FK or not); its users.
 *  2. CLOSURE: every row that references a collected row through a RESTRICT,
 *     NO ACTION or CASCADE foreign key, recursively. A SET NULL reference is
 *     not followed -- that row survives and is unlinked, as the schema intends
 *     (a support ticket stays in the queue without its organization).
 *  3. GUARD: if any collected row carries another tenant's `tenant_id`, or a
 *     `company_id` that is not one of this tenant's companies, NOTHING is
 *     deleted and the purge throws. Another customer's data is never collateral.
 *  4. DELETE in one transaction, foreign-key checks deferred for its duration,
 *     SET NULL references nulled explicitly first, so the result is exactly what
 *     the schema's own rules would produce.
 *
 * Users are deleted, not deactivated, so the address is free to register again
 * (the owner's requirement). Uploaded files are left on disk; they are
 * unreachable once their rows are gone.
 *
 * WHO MAY CALL purge(): TenantDeletionService only -- immediately for a test
 * organization that never paid, or after the 90-day hold for anybody else.
 */
class TenantPurgeService
{
    /** @var array<string, list<array{table:string,column:string,parent:string,parentColumn:string,onDelete:string}>> */
    private array $children = [];

    /** @return array<string,int> rows deleted per table */
    public function purgeTenant(Tenant $tenant): array
    {
        $companyIds = DB::table('companies')->where('tenant_id', $tenant->id)->pluck('id')->all();
        $seeds = ['tenants' => [$tenant->id]];

        foreach ($this->tables() as $table) {
            if ($table !== 'tenants' && Schema::hasColumn($table, 'tenant_id') && Schema::hasColumn($table, 'id')) {
                $seeds[$table] = array_merge($seeds[$table] ?? [], DB::table($table)->where('tenant_id', $tenant->id)->pluck('id')->all());
            }
            if ($companyIds && Schema::hasColumn($table, 'company_id') && Schema::hasColumn($table, 'id')) {
                $seeds[$table] = array_merge($seeds[$table] ?? [], DB::table($table)->whereIn('company_id', $companyIds)->pluck('id')->all());
            }
        }

        return $this->purge($seeds, $tenant->id, $companyIds);
    }

    /**
     * A registration that never became a paying organization: the registration,
     * its onboarding invoice(s) and their payment attempts, and the account that
     * signed up -- when that account belongs to no organization and is not staff.
     *
     * @return array<string,int>
     */
    public function purgeRegistration(TenantRegistration $registration): array
    {
        $invoiceIds = Invoice::withoutGlobalScopes()
            ->whereNull('tenant_id')
            ->where(fn ($q) => $q->where('registration_id', $registration->id)
                ->when($registration->invoice_id, fn ($q) => $q->orWhere('id', $registration->invoice_id)))
            ->pluck('id')->all();

        $seeds = ['tenant_registrations' => [$registration->id], 'invoices' => $invoiceIds];

        $user = $registration->user_id ? User::withoutGlobalScopes()->find($registration->user_id) : null;
        if ($user && $user->tenant_id === null && ! $user->isPlatformAdmin()
            && ! TenantRegistration::where('user_id', $user->id)->where('id', '!=', $registration->id)->exists()) {
            $seeds['users'] = [$user->id];
        }

        return $this->purge($seeds, null, []);
    }

    /**
     * @param  array<string, list<int>>  $seeds
     * @return array<string,int>
     */
    private function purge(array $seeds, ?int $tenantId, array $companyIds): array
    {
        $this->loadGraph();
        $collected = [];
        $queue = [];
        foreach ($seeds as $table => $ids) {
            $this->collect($table, $ids, $collected, $queue);
        }

        // CLOSURE: follow RESTRICT / NO ACTION / CASCADE references downwards.
        $nullOuts = [];
        while ($queue) {
            [$table, $ids] = array_shift($queue);
            foreach ($this->children[$table] ?? [] as $fk) {
                foreach (array_chunk($ids, 500) as $chunk) {
                    if ($fk['onDelete'] === 'set null') {
                        $nullOuts[] = [$fk['table'], $fk['column'], $chunk];

                        continue;
                    }
                    if (Schema::hasColumn($fk['table'], 'id')) {
                        $childIds = DB::table($fk['table'])->whereIn($fk['column'], $chunk)->pluck('id')->all();
                        $this->collect($fk['table'], $childIds, $collected, $queue);
                    } else {
                        // A pivot without its own key: removed by the reference itself.
                        $collected["pivot:{$fk['table']}:{$fk['column']}"] = array_merge($collected["pivot:{$fk['table']}:{$fk['column']}"] ?? [], $chunk);
                    }
                }
            }
        }

        $this->guard($collected, $tenantId, $companyIds);

        $deleted = [];
        Schema::withoutForeignKeyConstraints(function () use ($collected, $nullOuts, &$deleted) {
            DB::transaction(function () use ($collected, $nullOuts, &$deleted) {
                foreach ($nullOuts as [$table, $column, $ids]) {
                    $own = $collected[$table] ?? [];
                    DB::table($table)->whereIn($column, $ids)
                        ->when($own && Schema::hasColumn($table, 'id'), fn ($q) => $q->whereNotIn('id', $own))
                        ->update([$column => null]);
                }
                foreach ($this->deletionOrder(array_keys($collected)) as $key) {
                    $ids = $collected[$key];
                    if (str_starts_with($key, 'pivot:')) {
                        [, $table, $column] = explode(':', $key, 3);
                        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
                            $deleted[$table] = ($deleted[$table] ?? 0) + DB::table($table)->whereIn($column, $chunk)->delete();
                        }

                        continue;
                    }
                    foreach (array_chunk($ids, 500) as $chunk) {
                        $deleted[$key] = ($deleted[$key] ?? 0) + DB::table($key)->whereIn('id', $chunk)->delete();
                    }
                }
            });
        });

        // Sanctum tokens are a polymorphic reference with no foreign key.
        if (! empty($collected['users']) && Schema::hasTable('personal_access_tokens')) {
            $deleted['personal_access_tokens'] = DB::table('personal_access_tokens')
                ->where('tokenable_type', (new User)->getMorphClass())->whereIn('tokenable_id', $collected['users'])->delete();
        }

        return array_filter($deleted);
    }

    /** Refuse the whole purge if any collected row belongs to somebody else. */
    private function guard(array $collected, ?int $tenantId, array $companyIds): void
    {
        foreach ($collected as $table => $ids) {
            if (str_starts_with($table, 'pivot:') || ! $ids) {
                continue;
            }
            foreach (array_chunk($ids, 500) as $chunk) {
                if (Schema::hasColumn($table, 'tenant_id')) {
                    $foreign = DB::table($table)->whereIn('id', $chunk)->whereNotNull('tenant_id')
                        ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', '!=', $tenantId))
                        ->exists();
                    if ($foreign) {
                        throw new RuntimeException("Purge refused: {$table} holds a row of another organization.");
                    }
                }
                if (Schema::hasColumn($table, 'company_id')) {
                    $foreign = DB::table($table)->whereIn('id', $chunk)->whereNotNull('company_id')
                        ->when($companyIds, fn ($q) => $q->whereNotIn('company_id', $companyIds))
                        ->exists();
                    if ($foreign) {
                        throw new RuntimeException("Purge refused: {$table} holds a row of another organization's company.");
                    }
                }
            }
        }
        if (! empty($collected['users'])) {
            $staff = User::withoutGlobalScopes()->whereIn('id', $collected['users'])->where('role', User::ROLE_PLATFORM_ADMIN)->exists();
            if ($staff) {
                throw new RuntimeException('Purge refused: it would remove a platform administrator.');
            }
        }
    }

    /**
     * Children before parents, from the foreign keys among the collected tables
     * (Kahn's algorithm). The order alone satisfies RESTRICT on every engine; a
     * genuine cycle (rare: two tables that reference each other) is appended last
     * and relies on the deferred checks, which MySQL honours inside the transaction.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function deletionOrder(array $keys): array
    {
        $tableOf = fn (string $k) => str_starts_with($k, 'pivot:') ? explode(':', $k, 3)[1] : $k;
        $before = array_fill_keys($keys, []);   // key => keys that must be deleted before it
        foreach ($keys as $parentKey) {
            foreach ($this->children[$tableOf($parentKey)] ?? [] as $fk) {
                foreach ($keys as $childKey) {
                    if ($childKey !== $parentKey && $tableOf($childKey) === $fk['table']) {
                        $before[$parentKey][$childKey] = true;
                    }
                }
            }
        }
        $order = [];
        while ($before) {
            $ready = array_keys(array_filter($before, fn ($deps) => ! array_diff_key($deps, array_flip($order)) || ! array_intersect_key($deps, $before)));
            if (! $ready) {
                $order = array_merge($order, array_keys($before));
                break;
            }
            foreach ($ready as $k) {
                $order[] = $k;
                unset($before[$k]);
            }
        }

        return $order;
    }

    private function collect(string $table, array $ids, array &$collected, array &$queue): void
    {
        $new = array_values(array_diff(array_unique(array_map('intval', $ids)), $collected[$table] ?? []));
        if (! $new) {
            return;
        }
        $collected[$table] = array_merge($collected[$table] ?? [], $new);
        $queue[] = [$table, $new];
    }

    private function loadGraph(): void
    {
        if ($this->children) {
            return;
        }
        foreach ($this->tables() as $table) {
            foreach (Schema::getForeignKeys($table) as $fk) {
                if (count($fk['columns']) !== 1 || count($fk['foreign_columns']) !== 1 || $fk['foreign_columns'][0] !== 'id') {
                    continue;
                }
                $this->children[$fk['foreign_table']][] = [
                    'table' => $table,
                    'column' => $fk['columns'][0],
                    'parent' => $fk['foreign_table'],
                    'parentColumn' => 'id',
                    'onDelete' => strtolower((string) ($fk['on_delete'] ?? 'restrict')),
                ];
            }
        }
    }

    /** @return list<string> */
    private function tables(): array
    {
        // Laravel 12 lists every schema the connection can see; only this
        // application's own database is ever in scope ('main' on SQLite).
        $own = [null, DB::connection()->getDatabaseName(), 'main'];

        return collect(Schema::getTables())->filter(fn ($t) => in_array($t['schema'] ?? null, $own, true))->pluck('name')
            ->reject(fn ($t) => in_array($t, ['migrations', 'sqlite_sequence', 'jobs', 'failed_jobs', 'job_batches', 'cache', 'cache_locks', 'sessions', 'password_reset_tokens'], true))
            ->values()->all();
    }
}
