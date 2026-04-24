<?php

namespace App\Enums;

enum PushErrorType: string
{
    case Validation = 'validation';
    case Auth = 'auth';
    case RateLimit = 'rate_limit';
    case HttpError = 'http_error';
    case Network = 'network';
    case Other = 'other';
}
