<?php

declare(strict_types=1);

use App\Enums\AgentStatus;
use App\Enums\DriftSeverity;
use App\Enums\ReceiptStatus;
use App\Enums\RunState;
use App\Events\Board\AgentStatusChanged;
use App\Events\Board\DriftFlagRaised;
use App\Events\Board\ReceiptUpdated;
use App\Events\Board\RunUpdated;

function channelNames(object $event): array
{
    return collect($event->broadcastOn())->map(fn ($c) => $c->name)->all();
}

it('broadcasts a private-workspace run only on fleet.board', function (): void {
    $event = new RunUpdated('run1', 'agent1', RunState::Running, 'ws1', false);

    expect(channelNames($event))->toBe(['private-fleet.board']);
});

it('broadcasts a public-workspace run on both fleet.board and public.board', function (): void {
    $event = new RunUpdated('run1', 'agent1', RunState::Running, 'ws1', true);

    expect(channelNames($event))->toBe(['private-fleet.board', 'public.board']);
});

it('uses the frozen broadcastAs names from channels.md', function (): void {
    expect((new AgentStatusChanged('a', AgentStatus::Blocked, 'w', false))->broadcastAs())
        ->toBe('agent.status_changed')
        ->and((new RunUpdated('r', 'a', RunState::Done, 'w', false))->broadcastAs())
        ->toBe('run.updated')
        ->and((new DriftFlagRaised('d', 'a', DriftSeverity::Violation, 'w', false))->broadcastAs())
        ->toBe('drift.raised')
        ->and((new ReceiptUpdated('rc', 'r', ReceiptStatus::Attested, 'w', false))->broadcastAs())
        ->toBe('receipt.updated');
});

it('never leaks a url or private identifier in a ReceiptUpdated payload', function (): void {
    $payload = (new ReceiptUpdated('rc1', 'run1', ReceiptStatus::Attested, 'ws1', true))->broadcastWith();

    expect(array_keys($payload))->toBe(['receipt_id', 'run_id', 'status', 'workspace_id'])
        ->and($payload)->not->toHaveKey('url')
        ->and($payload)->not->toHaveKey('summary');
});

it('carries only id/enum scalars in every board payload', function (): void {
    $payloads = [
        (new AgentStatusChanged('a', AgentStatus::Blocked, 'w', true))->broadcastWith(),
        (new RunUpdated('r', 'a', RunState::Blocked, 'w', true))->broadcastWith(),
        (new DriftFlagRaised('d', 'a', DriftSeverity::Warn, 'w', true))->broadcastWith(),
    ];

    foreach ($payloads as $payload) {
        foreach ($payload as $value) {
            expect(is_string($value))->toBeTrue();
        }
    }
});
