<?php

namespace App\Services\Money;

use InvalidArgumentException;

/**
 * Money math for Spendula. SPEC §11:
 *  - internal representation is signed integer milliunits = native amount × 1000
 *  - never use PHP floats
 *  - rounding policy is truncation toward zero
 */
class Money
{
    /**
     * Enable Banking transaction amounts arrive as strings (see spike FINDINGS #7);
     * cast via bcmath so 0.10 × 1000 stays 100 (float would drift to 99.999…).
     * Sign comes from the credit_debit_indicator, not from the amount string.
     */
    public static function toMilliunits(string $amount, string $creditDebitIndicator): int
    {
        $cdi = strtoupper($creditDebitIndicator);
        if ($cdi !== 'CRDT' && $cdi !== 'DBIT') {
            throw new InvalidArgumentException("Unexpected credit_debit_indicator: {$creditDebitIndicator}");
        }

        $trimmed = trim($amount);
        if ($trimmed === '' || ! preg_match('/^-?\d+(\.\d+)?$/', $trimmed) || ! is_numeric($trimmed)) {
            throw new InvalidArgumentException("Unexpected amount format: {$amount}");
        }

        $milli = bcmul($trimmed, '1000', 0);
        $milliInt = (int) $milli;

        return $cdi === 'DBIT' ? -abs($milliInt) : abs($milliInt);
    }

    /** ISO 4217 minor-unit count for display; see SPEC §11. */
    public static function decimalPlaces(string $currency): int
    {
        return match (strtoupper($currency)) {
            'JPY', 'KRW' => 0,
            'BHD', 'KWD', 'OMR' => 3,
            default => 2,
        };
    }

    /**
     * milliunits → printable amount, e.g. 4770 EUR → "4.77", -4770 EUR → "-4.77",
     * 100000 JPY → "100". Currency symbol is not appended here; callers decide.
     */
    public static function format(int $milliunits, string $currency): string
    {
        $sign = $milliunits < 0 ? '-' : '';
        $abs = (string) abs($milliunits);
        $decimals = self::decimalPlaces($currency);

        // milliunits → major amount by dividing by 1000; bcmath truncation is already correct here.
        $major = bcdiv($abs, '1000', $decimals);

        return $sign.$major;
    }

    /** Currency symbol / short label for display (EUR → €, RON → RON, etc.). */
    public static function symbol(string $currency): string
    {
        return match (strtoupper($currency)) {
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
            default => strtoupper($currency),
        };
    }
}
