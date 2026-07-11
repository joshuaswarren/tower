<?php

declare(strict_types=1);

namespace App\Events\Board;

use App\Enums\ReceiptStatus;

/**
 * broadcastAs: `receipt.updated` — refreshes islands `receipts`, `feed`.
 *
 * Payload is ids + status only. The receipt URL is deliberately NOT broadcast
 * (not even on `fleet.board`): a broadcast is a refresh signal, and the URL is
 * rendered only in the auth-gated server view. This keeps `public.board`
 * structurally incapable of leaking an artifact link.
 */
final class ReceiptUpdated extends BoardBroadcast
{
    public function __construct(
        public readonly string $receiptId,
        public readonly string $runId,
        public readonly ReceiptStatus $status,
        string $workspaceId,
        bool $workspacePublic,
    ) {
        parent::__construct($workspaceId, $workspacePublic);
    }

    public function broadcastAs(): string
    {
        return 'receipt.updated';
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return [
            'receipt_id' => $this->receiptId,
            'run_id' => $this->runId,
            'status' => $this->status->value,
            'workspace_id' => $this->workspaceId,
        ];
    }
}
