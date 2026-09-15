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

// v2.70.0 (Subscription lifecycle). Issues renewal invoices, sends
// renewal reminders and applies plan changes scheduled for a period
// boundary. Daily in the early morning, because everything it acts on is
// measured in DAYS -- running it hourly would only raise the same
// invoice-shaped question twenty-four times for the same answer.
//
// IT DOES NOT GATE ACCESS. Grace and lapse are derived from the dates on
// every read, so a customer's access is correct whether this ran last
// night or has never run at all. A missed cron here delays an invoice and
// a reminder; it cannot lock anyone out and it cannot let anyone in.
// Requires the server's cron to call `php artisan schedule:run` every
// minute (standard Laravel setup).
Schedule::command('subscriptions:lifecycle')->dailyAt('02:00')->withoutOverlapping();
