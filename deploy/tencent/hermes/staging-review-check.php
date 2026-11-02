<?php

/*
 * staging-review-check.php -- READ-ONLY report of the Approval Flow review tenant on staging
 * (ADR 084). Installed root-owned at /usr/local/lib/ioms-staging-ops/review-check.php and run
 * by `ioms-staging-ops review-check` as the staging account:
 *
 *     php review-check.php /srv/ioms-staging/app
 *
 * It boots the staging application, opens a transaction, only READS, and rolls the
 * transaction back -- so even a future edit that wrote something by mistake could not keep it.
 * It prints no secret: e-mail addresses of the review accounts, statuses, counts and versions.
 */

use App\Models\Approval;
use App\Models\ApprovalFlowVersion;
use App\Models\PermitToWork;
use App\Models\PtwWorkflowSetting;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$app = $argv[1] ?? '';
if ($app !== '/srv/ioms-staging/app' || ! is_file($app.'/vendor/autoload.php')) {
    fwrite(STDERR, "review-check runs only against /srv/ioms-staging/app\n");
    exit(2);
}

chdir($app);
require $app.'/vendor/autoload.php';
$laravel = require $app.'/bootstrap/app.php';
$laravel->make(Kernel::class)->bootstrap();

if (config('database.connections.mysql.database') !== 'ioms_staging' || app()->environment('production')) {
    fwrite(STDERR, "refusing: not the staging database\n");
    exit(2);
}

$ok = true;
$line = fn (string $label, string $value, bool $pass = true) => printf("%-4s %-28s %s\n", $pass ? 'ok' : 'FAIL', $label, $value);

DB::beginTransaction();
try {
    $tenant = Tenant::where('slug', 'flow-review')->first();
    if (! $tenant) {
        $line('review tenant', 'flow-review NOT FOUND (run: flows)', false);
        exit(1);
    }
    app(CurrentTenant::class)->set($tenant);

    $emails = User::query()->where('email', 'like', 'flow.%@ioms.local')->orderBy('email')->pluck('email');
    $line('review accounts', $emails->count().' ('.$emails->implode(', ').')', $ok = $emails->count() === 8);

    $modes = PtwWorkflowSetting::with('company')->get()->mapWithKeys(fn ($s) => [$s->company->name => $s->approval_mode]);
    $modesOk = ($modes['Yard Utama'] ?? null) === 'flow' && ($modes['Yard Dua'] ?? null) === 'legacy';
    $line('approval modes', $modes->map(fn ($m, $c) => "$c=$m")->implode(', '), $modesOk);
    $ok = $ok && $modesOk;

    echo "\npermits (number | work | unit | status | flow version | start):\n";
    foreach (PermitToWork::with('company')->orderBy('id')->get() as $p) {
        $first = $p->approvals()->orderBy('id')->first();
        $version = $first?->approval_flow_version_id
            ? ApprovalFlowVersion::withoutGlobalScopes()->find($first->approval_flow_version_id)?->version
            : null;
        printf("  %-16s | %-34s | %-10s | %-9s | v%-3s | %s\n",
            $p->ptw_number, $p->work_reference, $p->company->name, $p->status, $version ?? '-', $p->start_datetime);
    }

    $statuses = Approval::query()->whereIn('approvable_id', PermitToWork::pluck('id'))
        ->where('approvable_type', (new PermitToWork)->getMorphClass())
        ->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status');
    echo "\n";
    $line('approval rows by status', $statuses->map(fn ($n, $s) => "$s=$n")->implode(', ') ?: 'none');

    $task = Task::where('task_number', 'TSK-FLW-001')->exists();
    $line('D1 task TSK-FLW-001', $task ? 'present' : 'missing', $task);
    $ok = $ok && $task;

    $other = PermitToWork::withoutGlobalScopes()->whereNotIn('company_id', $tenant->companies()->pluck('id'))->count();
    $line('other tenants (not visible)', "$other permits outside this tenant");
} finally {
    DB::rollBack();
}

exit($ok ? 0 : 1);
