<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $base_currency
 * @property string $quote_currency
 * @property Carbon $rate_date
 * @property string $rate
 * @property string $source
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ExchangeRate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rate_date' => 'date',
            // Keep `rate` as string to preserve full precision; callers pipe through bcmath.
        ];
    }
}
