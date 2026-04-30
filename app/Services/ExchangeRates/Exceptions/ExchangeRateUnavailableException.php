<?php

namespace App\Services\ExchangeRates\Exceptions;

/**
 * Provider returned 200 but the response did not contain the requested rate
 * (missing `rates.{quote}` field, malformed body, etc). Defensive: the
 * provider should never legitimately produce this for a supported pair, so
 * we surface rather than silently coerce to zero / null.
 */
class ExchangeRateUnavailableException extends ExchangeRateException {}
