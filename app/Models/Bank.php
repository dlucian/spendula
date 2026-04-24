<?php

namespace App\Models;

use App\Enums\PsuType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $slug
 * @property string $display_name
 * @property string $aspsp_name
 * @property string $aspsp_country
 * @property PsuType $psu_type
 * @property string $default_currency
 * @property int $sync_lookback_days
 * @property bool $active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Bank extends Model
{
    protected $primaryKey = 'slug';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'psu_type' => PsuType::class,
            'active' => 'boolean',
            'sync_lookback_days' => 'integer',
        ];
    }

    /** @return HasMany<BankConnection, $this> */
    public function connections(): HasMany
    {
        return $this->hasMany(BankConnection::class, 'bank_slug', 'slug');
    }

    /** @return HasMany<BankAccount, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(BankAccount::class, 'bank_slug', 'slug');
    }
}
