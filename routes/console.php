<?php

use Illuminate\Support\Facades\Schedule;

/*
| Cron: `* * * * * php artisan schedule:run` (PRD 9).
*/

// Database backup every 6 hours, copied off the VPS (BACKUP_DISK=r2), kept 30 days.
Schedule::command('backup:run --only-db')->everySixHours()->withoutOverlapping()->onOneServer();
Schedule::command('backup:clean')->dailyAt('03:30')->onOneServer();
Schedule::command('backup:monitor')->dailyAt('03:45')->onOneServer();

// Queue metrics for Horizon's dashboard.
Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Daily report (Z-report) without anyone pressing a button; checks every 30 minutes after the day changes.
Schedule::command('days:auto-close')->everyThirtyMinutes()->withoutOverlapping();

// QRIS online: expire old codes, catch missed webhooks, book payments that waited for an open shift.
Schedule::command('qris:sweep')->everyMinute()->withoutOverlapping();

// Monthly receipt PDFs are only kept for a month.
Schedule::command('receipt-exports:prune')->dailyAt('03:20');

// Notifications: keep the bell light.
Schedule::command('notifications:prune')->dailyAt('03:15');

// Housekeeping.
Schedule::command('queue:prune-batches --hours=48')->daily();
