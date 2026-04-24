<?php

namespace App\Enums;

enum SyncErrorType: string
{
    case ConsentExpired = 'consent_expired';
    case RateLimit = 'rate_limit';
    case HttpError = 'http_error';
    case ParseError = 'parse_error';
    case ConversionError = 'conversion_error';
    case Other = 'other';
}
