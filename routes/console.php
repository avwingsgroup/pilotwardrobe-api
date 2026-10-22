<?php

use App\Jobs\Payments\ReconcilePayments;
use Illuminate\Support\Facades\Schedule;

/*
| Scheduler — run `php artisan schedule:work` (dev) or a cron `* * * * * php artisan schedule:run`.
| Every task is idempotent; `onOneServer()` requires a shared cache (Redis) in production.
*/

// Time-based marketplace transitions.
Schedule::command('marketplace:sweep orders')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('marketplace:sweep offers')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('marketplace:sweep swaps')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('marketplace:sweep reservations')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('marketplace:sweep release')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('marketplace:sweep settle')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('marketplace:sweep reminders')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('marketplace:sweep disputes')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('marketplace:sweep promotions')->hourly()->onOneServer();
Schedule::command('marketplace:sweep kyc')->dailyAt('02:10')->onOneServer();
Schedule::command('marketplace:sweep payouts')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();

// Payment reconciliation catches missed webhooks.
Schedule::job(new ReconcilePayments(hours: 24))->everyTenMinutes()->onOneServer();

// Operations.
Schedule::command('status:record')->everyFiveMinutes()->onOneServer();
Schedule::command('pilotwardrobe:prune')->dailyAt('03:30')->onOneServer();
Schedule::command('sanctum:prune-expired --hours=24')->daily()->onOneServer();
Schedule::command('queue:prune-failed --hours=720')->daily()->onOneServer();
Schedule::command('horizon:snapshot')->everyFiveMinutes()->when(fn () => config('queue.default') === 'redis');
