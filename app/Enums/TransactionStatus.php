<?php

declare(strict_types=1);

namespace App\Enums;

enum TransactionStatus: string
{
    case Fetched = 'fetched';
    case Approved = 'approved';
    case Skipped = 'skipped';
    case Transfer = 'transfer';
    case Pushed = 'pushed';
    case Tracking = 'tracking';
    /**
     * GH #16 — own-account cross-source top-up: the Revolut-side CRDT leg is
     * suppressed after the funding-bank DBIT leg is identified as the canonical
     * survivor. A transfer_dropped row is never pushed to YNAB; it is linked to
     * the surviving funding leg via transactions.linked_transfer_id (self-FK).
     * Terminal — no state transitions out of transfer_dropped.
     */
    case TransferDropped = 'transfer_dropped';
}
