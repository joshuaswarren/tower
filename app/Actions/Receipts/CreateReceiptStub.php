<?php

declare(strict_types=1);

namespace App\Actions\Receipts;

use App\Enums\ReceiptStatus;
use App\Models\Receipt;
use App\Models\Run;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Create the unattested receipt stub for a `done` run
 * (ARCHITECTURE.md §1.2 honesty model).
 *
 * Idempotency: a run may receive multiple `done` events (producer retry,
 * dedupe replay, etc.). We never create more than ONE `unattested` stub per
 * run. A repeat call is a no-op and returns the existing row.
 *
 * The `stub` jsonb is FROZEN at creation: it captures the run context so the
 * board can render the receipt honestly even if the run is gone.
 */
class CreateReceiptStub
{
    /**
     * @return array{receipt: Receipt, created: bool}
     */
    public function execute(Run $run): array
    {
        $existing = Receipt::query()
            ->where('run_id', $run->id)
            ->where('status', ReceiptStatus::Unattested)
            ->first();

        if ($existing !== null) {
            return ['receipt' => $existing, 'created' => false];
        }

        $receipt = DB::transaction(function () use ($run): Receipt {
            $stub = [
                'agent' => $run->agent?->name,
                'workspace' => $run->agent?->workspace?->name,
                'duration_ms' => $run->duration_ms,
                'exit_state' => $run->exit_state,
                'done_at' => ($run->ended_at ?? CarbonImmutable::now())->toIso8601String(),
            ];

            return Receipt::query()->create([
                'run_id' => $run->id,
                'agent_id' => $run->agent_id,
                'status' => ReceiptStatus::Unattested,
                'kind' => null,
                'url' => null,
                'summary' => null,
                'stub' => $stub,
            ]);
        });

        return ['receipt' => $receipt, 'created' => true];
    }
}
