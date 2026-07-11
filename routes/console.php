<?php

declare(strict_types=1);

use App\Console\Commands\PruneEvents;
use App\Console\Commands\SweepStaleAgents;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Tower scheduler (docs/ARCHITECTURE.md §1.8).
 *
 * - tower:sweep-stale every minute: heartbeat-based offline transitions.
 * - tower:prune-events daily: bounded retention on the events hot table.
 *
 * Both are gated on the commands being available; the Schedule facade
 * just needs the closure bodies.
 */
Schedule::command(SweepStaleAgents::class)->everyMinute()->name('tower-sweep-stale');
Schedule::command(PruneEvents::class)->dailyAt('03:17')->name('tower-prune-events');
