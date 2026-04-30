<?php

namespace App\Services\ExchangeRates;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A resolved exchange rate as seen by a caller. `rateDate` is the date the
 * provider actually published the rate for, which may be earlier than the
 * date the caller requested (Frankfurter rolls back to the most recent
 * business day on weekends and holidays).
 *
 * `rate` is preserved as a string at full provider precision; callers must
 * route arithmetic through `bcmath` per CLAUDE.md money rules.
 */
final readonly class Rate
{
    public CarbonImmutable $rateDate;

    public function __construct(
        public string $base,
        public string $quote,
        CarbonInterface $rateDate,
        public string $rate,
        public string $source,
    ) {
        $this->rateDate = CarbonImmutable::instance($rateDate)->startOfDay();
    }
}
