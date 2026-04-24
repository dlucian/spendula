<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $state
 * @property string $bank_slug
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AuthRequest extends Model
{
    use HasUuidV7;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Bank, $this> */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bank_slug', 'slug');
    }
}
