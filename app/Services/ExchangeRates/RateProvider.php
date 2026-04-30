<?php

namespace App\Services\ExchangeRates;

use App\Services\ExchangeRates\Exceptions\ExchangeRateProviderUnreachableException;
use App\Services\ExchangeRates\Exceptions\ExchangeRateUnavailableException;
use Carbon\CarbonInterface;

/**
 * Minimal seam over an exchange-rate source. One implementation today
 * (`FrankfurterClient`); the interface exists so the rest of the app
 * type-hints the abstraction and the wired impl is whatever
 * `config('spendula.exchange_rates.provider')` names.
 */
interface RateProvider
{
    /**
     * Resolve the {base}->{quote} rate for the requested calendar date.
     *
     * Success: returns a {@see Rate} whose `rateDate` is the date the
     *   underlying source actually published a rate for. That date may
     *   be earlier than `$date` when the requested date is a weekend or
     *   holiday — providers like Frankfurter roll back to the most
     *   recent business day. The returned `rate` is a full-precision
     *   string; callers route arithmetic through bcmath.
     *
     * Failure: throws {@see ExchangeRateProviderUnreachableException} on
     *   transport failure or non-2xx after retries (SPEC §5.5: hard
     *   fail; the snapshot caller aborts). Throws
     *   {@see ExchangeRateUnavailableException} on 200 with a malformed
     *   body that is missing the requested pair.
     */
    public function getRate(string $base, string $quote, CarbonInterface $date): Rate;
}
