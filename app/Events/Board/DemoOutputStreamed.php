<?php

declare(strict_types=1);

namespace App\Events\Board;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Demo stream chunk (docs/contracts/channels.md — FROZEN).
 *
 *   broadcastAs : `demo.chunk`
 *   channel     : `demo.run.{runId}`  (public)
 *   queued      : NO — streamed inline from RunDemoAgent for latency
 *   payload     : {run_id, seq, chunk}
 *
 * Implements ShouldBroadcast but does NOT use the Queueable trait: the
 * parent `Job`/queue pipeline would add a round-trip through the
 * `ingest` queue, defeating the point of streaming. The job in
 * App\Jobs\RunDemoAgent dispatches chunks synchronously inside the
 * worker so the visitor sees deltas as they're produced.
 */
final class DemoOutputStreamed implements ShouldBroadcast
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $runId,
        public readonly int $seq,
        public readonly string $chunk,
    ) {
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('demo.run.'.$this->runId)];
    }

    public function broadcastAs(): string
    {
        return 'demo.chunk';
    }

    /**
     * @return array<string, int|string>
     */
    public function broadcastWith(): array
    {
        return [
            'run_id' => $this->runId,
            'seq' => $this->seq,
            'chunk' => $this->chunk,
        ];
    }
}
