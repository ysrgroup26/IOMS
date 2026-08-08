<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Milestone 3 (Universal Approval Engine v2 -- escalation). Hourly is
// coarse enough not to spam, fine enough that `escalate_after_hours`
// settings (typically set in whole hours) are respected within an hour
// of their deadline. Requires the server's cron to call
// `php artisan schedule:run` every minute (standard Laravel setup).
Schedule::command('approvals:escalate')->hourly();

// Milestone 3 (Report Center, Task #65). Hourly is coarse enough not to
// spam even for 'daily' schedules (next_run_at is a real timestamp
// compared with <=, so a schedule never fires twice for one due window
// regardless of check frequency).
Schedule::command('reports:dispatch-scheduled')->hourly();
