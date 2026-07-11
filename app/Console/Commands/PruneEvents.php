<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `tower:prune-events`
 *
 * Scheduled daily. Deletes events whose `received_at` is older than
 * `config('tower.retention.events_days')` (default 30). Chunked to avoid
 * holding long-lived locks on the hot table.
 *
 * ADR-0003: no partitioning; a personal fleet emits thousands/day, not
 * millions. The BRIN + scheduled prune is enough.
 */
class PruneEvents extends Command
{
    protected $signature = 'tower:prune-events {--chunk=500 : Rows per delete batch}';

    protected $description = 'Delete events older than `tower.retention.events_days` (chunked).';

    public function handle(): int
    {
        $days = (int) config('tower.retention.events_days', 30);
        $chunk = (int) $this->option('chunk');
        if ($chunk < 1) {
            $chunk = 500;
        }

        $cutoff = now()->subDays(max($days, 0));
        $total = 0;

        do {
            $deleted = DB::table('events')
                ->where('received_at', '<', $cutoff)
                ->limit($chunk)
                ->delete();
            $total += (int) $deleted;
        } while ($deleted > 0);

        $this->info("Pruned {$total} event(s) older than {$cutoff->toIso8601String()}.");
        return self::SUCCESS;
    }
}
