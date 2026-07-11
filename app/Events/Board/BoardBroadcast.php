<?php

declare(strict_types=1);

namespace App\Events\Board;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Base for every board broadcast (docs/contracts/channels.md — FROZEN).
 *
 * A broadcast is only a SIGNAL to refresh a named island (ADR-0004); the
 * board always re-renders server-side, so these payloads carry ids/enums
 * only — never sensitive content. That is why the identical payload is safe
 * to emit on both `fleet.board` and (for public workspaces) `public.board`.
 *
 * Queued on the `ingest` queue so board fan-out never starves behind demo
 * runs (ADR-0005).
 */
abstract class BoardBroadcast implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly string $workspaceId,
        public readonly bool $workspacePublic,
    ) {
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('fleet.board')];

        if ($this->workspacePublic) {
            $channels[] = new Channel('public.board');
        }

        return $channels;
    }

    public function broadcastQueue(): string
    {
        return 'ingest';
    }
}
