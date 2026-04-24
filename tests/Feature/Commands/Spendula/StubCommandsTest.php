<?php

namespace Tests\Feature\Commands\Spendula;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StubCommandsTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function stubCommands(): array
    {
        return [
            'spendula:accounts:map' => ['spendula:accounts:map'],
            'spendula:status' => ['spendula:status'],
            'spendula:convert-pending' => ['spendula:convert-pending'],
            'spendula:tracking:snapshot' => ['spendula:tracking:snapshot'],
        ];
    }

    #[DataProvider('stubCommands')]
    public function test_command_runs_cleanly(string $signature): void
    {
        $this->artisan($signature)
            ->expectsOutputToContain('not yet implemented')
            ->assertSuccessful();
    }
}
