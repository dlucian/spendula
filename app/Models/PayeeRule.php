<?php

namespace App\Models;

use App\Enums\TransactionStatus;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $bank_slug
 * @property string $counterparty_name
 * @property TransactionStatus $action
 * @property ?string $skip_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class PayeeRule extends Model
{
    use HasUuidV7;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'action' => TransactionStatus::class,
        ];
    }

    /** @return BelongsTo<Bank, $this> */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bank_slug', 'slug');
    }
}
