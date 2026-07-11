<?php

declare(strict_types=1);

namespace App\Actions\Receipts;

use App\Enums\ReceiptStatus;
use App\Events\Board\ReceiptUpdated;
use App\Models\ApiToken;
use App\Models\Receipt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Move a receipt from `unattested` to `attested` (ARCHITECTURE.md §1.2).
 *
 * The honesty contract:
 *  - attestation is irreversible (second attempt -> 409, row unchanged),
 *  - requires a non-empty `url` or `summary`,
 *  - attributed to either a `User` session (admin UI) or an `ApiToken`
 *    carrying the `attest` ability (machine path),
 *  - emits a `receipt.attested` event row for the feed,
 *  - broadcasts `ReceiptUpdated` so the board's `receipts` island refreshes.
 */
class AttestReceipt
{
    /**
     * @return array{receipt: Receipt, transitioned: bool}
     */
    public function execute(
        Receipt $receipt,
        string $attestorType,
        string $attestorId,
        ?string $url,
        ?string $summary,
        ?string $kind = null,
    ): array {
        $url = is_string($url) ? trim($url) : '';
        $summary = is_string($summary) ? trim($summary) : '';

        if ($url === '' && $summary === '') {
            throw new \InvalidArgumentException(
                'attestation requires a non-empty url or summary'
            );
        }

        if ($receipt->status === ReceiptStatus::Attested) {
            return ['receipt' => $receipt, 'transitioned' => false];
        }

        return DB::transaction(function () use (
            $receipt, $attestorType, $attestorId, $url, $summary, $kind,
        ): array {
            $receipt->forceFill([
                'status' => ReceiptStatus::Attested,
                'kind' => $kind,
                'url' => $url !== '' ? $url : null,
                'summary' => $summary !== '' ? $summary : null,
                'attested_by_type' => $attestorType,
                'attested_by_id' => $attestorId,
                'attested_at' => CarbonImmutable::now(),
            ])->save();

            $receipt->refresh();

            return ['receipt' => $receipt, 'transitioned' => true];
        });
    }

    /**
     * Resolve the principal of the attestor for a token-driven call.
     * Caller is the HTTP layer (controller) which knows whether the
     * principal is a User session or an ApiToken.
     */
    public function tokenPrincipalId(ApiToken $token): string
    {
        return (string) $token->id;
    }

    public function userPrincipalId(User $user): string
    {
        return (string) $user->id;
    }
}
