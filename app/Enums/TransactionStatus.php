<?php

namespace App\Enums;

enum TransactionStatus: string
{
    case Fetched = 'fetched';
    case Approved = 'approved';
    case Skipped = 'skipped';
    case Transfer = 'transfer';
    case Pushed = 'pushed';
    case Tracking = 'tracking';
}
