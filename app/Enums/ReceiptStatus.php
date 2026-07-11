<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tower receipt status — see docs/ARCHITECTURE.md §1.2 (receipts.status).
 *
 * `unattested -> attested` is the only legal transition and is gated by
 * AttestReceipt (User session or token with `attest` ability, requires a
 * non-empty `url` or `summary`). Attestation is immutable.
 */
enum ReceiptStatus: string
{
    case Unattested = 'unattested';
    case Attested = 'attested';
}
