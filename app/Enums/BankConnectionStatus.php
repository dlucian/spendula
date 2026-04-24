<?php

namespace App\Enums;

enum BankConnectionStatus: string
{
    case Active = 'active';
    case Superseded = 'superseded';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case Failed = 'failed';
}
