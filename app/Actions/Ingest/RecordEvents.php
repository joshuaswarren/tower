<?php

declare(strict_types=1);

namespace App\Actions\Ingest;

use App\Enums\EventType;
use App\Enums\RunState;
use App\Models\Agent;
use App\Models\Event;
use App\Models\Run;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Synchronous ingest path (ARCHITECTURE.md §1.3 + §1.4 + ADR).
 *
 * For each event in the batch:
 *  - validate shape (already done by StoreEventsRequest),
 *  - find-or-upsert the referenced run on (agent_id, external_id),
 *  - apply the state transition (ApplyRunTransition); illegal transitions
 *    keep the event with `payload.transition_rejected=true`,
 *  - insert the event row (dedupe_key conflicts are caught and counted),
 *  - bump agent denormalized columns (last_event_at, last_heartbeat_at,
 *    status where applicable).
 *
 * All work is inside a single transaction. The 202 response carries
 * per-event accepted/duplicates/rejected counts so the producer can
 * reconcile without losing 99 good events to one bad one.
 */
class RecordEvents
{
    public function __construct(
        private readonly ApplyRunTransition $applyTransition,
    ) {
    }

    /**
     * @param  Agent  $agent  The agent this batch belongs to (resolved from
     *                        the authenticated token's owner or from the
     *                        envelope `agent` key when the token is host-owned).
     * @param  array<string, mixed>  $envelope  Decoded `tower.ingest.v1` body.
     * @return array{accepted:int, duplicates:int, rejected:list<array{index:int,error:string}>, event_ids:list<int>}
     */
    public function execute(Agent $agent, array $envelope): array
    {
        $events = (array) ($envelope['events'] ?? []);
        $accepted = 0;
        $duplicates = 0;
        $rejected = [];
        $eventIds = [];

        $agentId = (string) $agent->id;
        $bumpHeartbeat = false;

        DB::transaction(function () use (
            $agent, $events, $envelope, $agentId, &$accepted, &$duplicates,
            &$rejected, &$eventIds, &$bumpHeartbeat,
        ): void {
            foreach ($events as $index => $event) {
                $event = (array) $event;
                $type = $event['type'] ?? null;

                if ($type === EventType::AgentHeartbeat->value) {
                    $bumpHeartbeat = true;
                }

                $run = null;
                if ($type === EventType::RunStateChanged->value) {
                    $run = $this->upsertRun($agent, (array) ($event['run'] ?? []));
                }

                $transition = null;
                $payload = (array) ($event['payload'] ?? []);

                if ($run !== null && $type === EventType::RunStateChanged->value) {
                    $toValue = $event['to'] ?? null;
                    $fromValue = $event['from'] ?? null;
                    $exitState = $event['exit_state'] ?? null;
                    $transitionTarget = $toValue !== null
                        ? RunState::tryFrom((string) $toValue)
                        : null;

                    $transition = $this->applyTransition->execute(
                        $run,
                        $fromValue !== null ? RunState::tryFrom((string) $fromValue) : null,
                        $transitionTarget,
                        $exitState !== null ? (string) $exitState : null,
                        $this->occurredAt($event),
                    );

                    // Stamp the resulting run state into the event row so the
                    // board can render the transition without re-deriving it.
                    $payload['_from'] = $transition['from']?->value;
                    $payload['_to'] = $transition['to']?->value;
                    if ($transition['duration_ms'] !== null) {
                        $payload['_duration_ms'] = $transition['duration_ms'];
                    }

                    if ($transition['rejected']) {
                        $payload['transition_rejected'] = true;
                        $payload['transition_rejected_error'] = $transition['error'];
                        // Per ARCHITECTURE.md §1.3, the rejected transition
                        // is also returned in the response's `rejected[]`
                        // array so the producer can reconcile.
                        $rejected[] = [
                            'index' => $index,
                            'error' => (string) $transition['error'],
                        ];
                    }
                }

                // Per-event savepoint: a dedupe-key conflict is a soft
                // failure (counted, not raised) and must not poison the
                // outer transaction. Postgres aborts the statement on
                // 23505 but a savepoint lets the next event proceed.
                $sp = 'ev_'.$index;
                DB::statement('SAVEPOINT '.$sp);
                try {
                    $row = $this->insertEventRow(
                        $agent,
                        $run,
                        $type !== null ? EventType::from((string) $type) : null,
                        $event,
                        $payload,
                        $transition,
                    );
                    DB::statement('RELEASE SAVEPOINT '.$sp);
                    $accepted++;
                    $eventIds[] = $row->id;
                } catch (QueryException $e) {
                    DB::statement('ROLLBACK TO SAVEPOINT '.$sp);
                    if ($this->isDedupeConflict($e)) {
                        $duplicates++;
                    } else {
                        throw $e;
                    }
                } catch (Throwable $e) {
                    DB::statement('ROLLBACK TO SAVEPOINT '.$sp);
                    $rejected[] = [
                        'index' => $index,
                        'error' => 'insert failed: '.$e->getMessage(),
                    ];
                }
            }

            // Final agent denormalization. last_heartbeat_at is bumped for
            // heartbeats; last_event_at is bumped for any event; status is
            // pulled forward only if a run transition set it.
            $updates = [];
            if ($bumpHeartbeat) {
                $updates['last_heartbeat_at'] = now();
            }
            $updates['last_event_at'] = now();
            $latestStatus = $this->latestStatusFromBatch($events, $envelope);
            if ($latestStatus !== null) {
                $updates['status'] = $latestStatus;
            }
            if (!empty($updates)) {
                Agent::query()->where('id', $agent->id)->update($updates);
            }
        });

        return [
            'accepted' => $accepted,
            'duplicates' => $duplicates,
            'rejected' => $rejected,
            'event_ids' => $eventIds,
        ];
    }

    private function upsertRun(Agent $agent, array $runRef): Run
    {
        $externalId = (string) ($runRef['external_id'] ?? '');
        if ($externalId === '') {
            throw new \InvalidArgumentException('run.external_id is required');
        }

        $existing = Run::query()
            ->where('agent_id', $agent->id)
            ->where('external_id', $externalId)
            ->first();

        if ($existing !== null) {
            $title = $runRef['title'] ?? null;
            if (is_string($title) && $title !== '' && $existing->title === null) {
                $existing->forceFill(['title' => $title])->save();
            }
            return $existing;
        }

        return Run::query()->create([
            'id' => (string) Str::ulid(),
            'agent_id' => $agent->id,
            'external_id' => $externalId,
            'title' => $runRef['title'] ?? null,
            'state' => RunState::Queued,
            'meta' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function insertEventRow(
        Agent $agent,
        ?Run $run,
        ?EventType $type,
        array $event,
        array $payload,
        ?array $transition,
    ): Event {
        $dedupeKey = $event['dedupe_key'] ?? null;
        $dedupeKey = is_string($dedupeKey) && $dedupeKey !== '' ? $dedupeKey : null;

        return Event::query()->create([
            'agent_id' => $agent->id,
            'run_id' => $run?->id,
            'type' => $type?->value,
            'from_state' => is_array($transition) ? ($transition['from']?->value) : null,
            'to_state' => is_array($transition) ? ($transition['to']?->value) : null,
            'payload' => $payload,
            'source' => 'api',
            'dedupe_key' => $dedupeKey,
            'occurred_at' => $this->occurredAt($event),
            'received_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function occurredAt(array $event): CarbonImmutable
    {
        $raw = $event['occurred_at'] ?? null;
        if (is_string($raw) && $raw !== '') {
            try {
                return CarbonImmutable::parse($raw);
            } catch (Throwable) {
                // fall through
            }
        }
        return CarbonImmutable::now();
    }

    /**
     * Postgres unique-constraint violation: SQLSTATE 23505.
     */
    private function isDedupeConflict(QueryException $e): bool
    {
        return $e->getCode() === '23505';
    }

    /**
     * Pick the most authoritative status from the batch (a terminal run
     * transition wins). We don't have $run->fresh() here because we're
     * inside the transaction; this returns what the controller should write
     * to `agents.status` for the most recent known state.
     *
     * @param  list<array<string, mixed>>  $events
     * @param  array<string, mixed>  $envelope
     */
    /**
     * Pick the most authoritative status from the batch (a terminal run
     * transition wins) and MAP it from a RunState string to the agent's
     * denormalized status. `running` => `working` etc. (see
     * ApplyRunTransition::mapRunStateToAgentStatus).
     *
     * @param  list<array<string, mixed>>  $events
     * @param  array<string, mixed>  $envelope
     */
    private function latestStatusFromBatch(array $events, array $envelope): ?string
    {
        $status = null;
        foreach ($events as $event) {
            $type = $event['type'] ?? null;
            $to = $event['to'] ?? null;
            if ($type === EventType::RunStateChanged->value && is_string($to) && $to !== '') {
                $runState = RunState::tryFrom($to);
                if ($runState !== null) {
                    $status = $this->applyTransition->mapRunStateToAgentStatus($runState)->value;
                }
            }
        }
        return $status;
    }
}
