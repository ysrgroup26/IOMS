<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Employee;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * v2.108.1 -- FINDING THE JOIN DATES THE OLD IMPORTER INVENTED.
 *
 * Before v2.108.0 the employee import read a join date with
 * `date('Y-m-d', strtotime($cell))`. `strtotime()` answers `false` for
 * anything it cannot read, `date()` casts `false` to 0, and 0 is the Unix
 * epoch -- so every unreadable cell was stored as 1 JANUARY 1970. The
 * parser is fixed, but the rows it already wrote are still in production
 * databases, and they do not look broken: they look like a date.
 *
 * WHAT THIS COMMAND WILL AND WILL NOT DO.
 *
 * It REPORTS. It names every employee whose `join_date` is the epoch,
 * grouped by Operating Unit, so the owner can check them against the
 * spreadsheet they imported from -- which is the only trustworthy source
 * of what those dates should have been.
 *
 * It does NOT guess. There is no evidence inside IOMS of what an
 * unreadable cell said: `activity_logs` records that an import happened
 * and how many rows landed, never the cell values. Any "recovery" that
 * derived a date from a contract, a KPI record or a neighbouring row
 * would be inventing history a second time, which is the whole fault
 * being cleaned up here.
 *
 * `--clear` is the one write it offers, and it is deliberately narrow: it
 * sets those join dates to NULL. That is not a guess; it is the removal
 * of a value nobody typed, leaving the field in the state the fixed
 * importer would produce today -- empty, and visibly needing an answer.
 * It touches `join_date` and nothing else.
 *
 * `--since` exists because 1 January 1970 can in principle be somebody's
 * real date of joining. It is not anybody's in a live workforce register,
 * but the option lets an operator restrict the sweep to employees created
 * after a given date -- for instance, only those added by the import that
 * went wrong.
 */
class AuditFabricatedJoinDates extends Command
{
    /** The value the old expression produced for every unreadable cell. */
    private const EPOCH = '1970-01-01';

    protected $signature = 'employees:audit-join-dates
        {--clear : Set the fabricated epoch join dates to NULL (nothing else is touched)}
        {--since= : Only employees created on or after this date, e.g. 2026-09-01}
        {--tenant= : Restrict to one tenant id}';

    protected $description = 'Report (and optionally clear) employee join dates of 1970-01-01 left behind by the pre-v2.108.0 import.';

    public function handle(): int
    {
        /*
         * Global scopes are deliberately bypassed. This is an operator
         * tool run from the server console with no tenant in context, and
         * its whole job is to see every affected row; the tenant is shown
         * in the output instead, so the operator can take the right file
         * to the right customer.
         */
        $query = Employee::withoutGlobalScopes()
            ->withTrashed()
            ->whereDate('join_date', self::EPOCH);

        if ($since = $this->option('since')) {
            $query->whereDate('created_at', '>=', $since);
        }

        if ($tenantId = $this->option('tenant')) {
            $companyIds = Company::withoutGlobalScopes()->where('tenant_id', $tenantId)->pluck('id');
            $query->whereIn('company_id', $companyIds);
        }

        $affected = $query->orderBy('company_id')->orderBy('employee_id')->get();

        if ($affected->isEmpty()) {
            $this->info('No employee carries a join date of '.self::EPOCH.'. Nothing to do.');

            return self::SUCCESS;
        }

        $companies = Company::withoutGlobalScopes()
            ->whereIn('id', $affected->pluck('company_id')->unique())
            ->get()
            ->keyBy('id');

        $this->warn($affected->count().' employee(s) carry a join date of '.self::EPOCH.'.');
        $this->line('This is the value the pre-v2.108.0 import wrote whenever it could not read a date cell.');
        $this->newLine();

        $this->table(
            ['Tenant', 'Operating Unit', 'Employee ID', 'Name', 'Imported at', 'Removed'],
            $affected->map(fn (Employee $e) => [
                $companies[$e->company_id]->tenant_id ?? '?',
                $companies[$e->company_id]->name ?? '?',
                $e->employee_id,
                $e->full_name,
                $e->created_at?->toDateString() ?? '?',
                $e->deleted_at ? 'yes' : '',
            ])->all()
        );

        $this->newLine();
        $this->line('The ONLY trustworthy source for what these dates should be is the spreadsheet');
        $this->line('they were imported from. IOMS does not record the original cell values, so');
        $this->line('nothing here can reconstruct them -- correct them from that file, or clear them.');

        if (! $this->option('clear')) {
            $this->newLine();
            $this->comment('Report only. Re-run with --clear to set these join dates to NULL.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Set '.$affected->count().' join date(s) to NULL? No other field is touched.')) {
            $this->info('Nothing was changed.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($affected) {
            Employee::withoutGlobalScopes()
                ->withTrashed()
                ->whereIn('id', $affected->pluck('id'))
                ->update(['join_date' => null]);

            ActivityLog::record(
                'updated',
                'Cleared '.$affected->count().' fabricated join date(s) of '.self::EPOCH.' left by the pre-v2.108.0 import.',
                null,
                ['employee_ids' => $affected->pluck('id')->all()]
            );
        });

        $this->info('Cleared '.$affected->count().' join date(s). Every other field is unchanged.');

        return self::SUCCESS;
    }
}
