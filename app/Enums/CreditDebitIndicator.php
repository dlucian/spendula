<?php

namespace App\Enums;

enum CreditDebitIndicator: string
{
    case Credit = 'CRDT';
    case Debit = 'DBIT';
}
