<?php

namespace Tests\Unit\Services\Money;

use App\Services\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function milliunitsProvider(): array
    {
        return [
            'simple CRDT' => ['4.77', 'CRDT', 4770],
            'simple DBIT' => ['4.77', 'DBIT', -4770],
            'precision preserved' => ['0.10', 'CRDT', 100],
            'tricky 0.01' => ['0.01', 'CRDT', 10],
            'integer amount' => ['100', 'CRDT', 100000],
            'large' => ['12345.67', 'DBIT', -12345670],
            'negative sign-in-amount ignored' => ['-4.77', 'DBIT', -4770],
        ];
    }

    #[DataProvider('milliunitsProvider')]
    public function test_to_milliunits_is_deterministic_and_signed_correctly(string $amount, string $cdi, int $expected): void
    {
        $this->assertSame($expected, Money::toMilliunits($amount, $cdi));
    }

    public function test_invalid_cdi_raises(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::toMilliunits('1.00', 'FOO');
    }

    public function test_invalid_amount_raises(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::toMilliunits('not-a-number', 'CRDT');
    }

    public function test_decimal_places_per_currency(): void
    {
        $this->assertSame(2, Money::decimalPlaces('EUR'));
        $this->assertSame(2, Money::decimalPlaces('RON'));
        $this->assertSame(0, Money::decimalPlaces('JPY'));
        $this->assertSame(3, Money::decimalPlaces('KWD'));
    }

    public function test_format_round_trips(): void
    {
        $this->assertSame('4.77', Money::format(4770, 'EUR'));
        $this->assertSame('-4.77', Money::format(-4770, 'EUR'));
        $this->assertSame('100', Money::format(100000, 'JPY'));
        $this->assertSame('0.10', Money::format(100, 'EUR'));
        $this->assertSame('0.001', Money::format(1, 'KWD'));
    }

    public function test_format_truncates_not_rounds(): void
    {
        // 4761 milliunits in EUR = 4.761 — SPEC §11 says truncation: "4.76".
        $this->assertSame('4.76', Money::format(4761, 'EUR'));
    }
}
