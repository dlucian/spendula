<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;

/**
 * UUIDv7 primary keys. Naturally ordered by timestamp, which plays well with
 * Postgres B-tree indexes without the pathological insert pattern of v4.
 */
trait HasUuidV7
{
    use HasUuids;

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return [$this->getKeyName()];
    }
}
