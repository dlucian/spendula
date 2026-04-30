<?php

namespace App\Services\ExchangeRates\Exceptions;

/**
 * Provider could not be reached: transport failure, or non-2xx after the
 * client's retry budget is exhausted. Per SPEC §5.5 this is a hard fail —
 * snapshots that depend on the rate must abort, not silently substitute.
 */
class ExchangeRateProviderUnreachableException extends ExchangeRateException {}
