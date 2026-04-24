<?php

namespace App\Services\Sync;

enum ApplyOutcome: string
{
    case Inserted = 'inserted';
    case Updated = 'updated';
    case Deduped = 'deduped';
}
