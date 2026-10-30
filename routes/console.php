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

// A shift left open for more than half a day is probably forgotten: tell the managers.
Schedule::command('shifts:remind-open')->everyThirtyMinutes()->withoutOverlapping();

// Fixed monthly costs: make the month's bills and remind about the ones falling due.
Schedule::command('fixed-expenses:remind')->dailyAt('08:00')->withoutOverlapping()->onOneServer();

// QRIS online: expire old codes, catch missed webhooks, book payments that waited for an open shift.
Schedule::command('qris:sweep')->everyMinute()->withoutOverlapping();

// Monthly receipt PDFs are only kept for a month.
Schedule::command('receipt-exports:prune')->dailyAt('03:20');

// Notifications: keep the bell light.
Schedule::command('notifications:prune')->dailyAt('03:15');

// Housekeeping.
Schedule::command('queue:prune-batches --hours=48')->daily();
