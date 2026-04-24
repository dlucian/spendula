<?php

namespace Tests\Unit\Services\Sync;

use App\Services\Sync\DedupHasher;
use PHPUnit\Framework\TestCase;

class DedupHasherTest extends TestCase
{
    public function test_dedup_hash_is_deterministic(): void
    {
        $a = DedupHasher::dedupHash('acc-1', '2026-04-15', -3457, 'EUR', 'DBIT', 'Pingo Doce', 'uxr2h');
        $b = DedupHasher::dedupHash('acc-1', '2026-04-15', -3457, 'EUR', 'DBIT', 'Pingo Doce', 'uxr2h');

        $this->assertSame($a, $b);
        $this->assertSame(32, strlen($a));
    }

    public function test_dedup_hash_normalizes_counterparty(): void
    {
        // "Pingo Doce" and "PINGO DOCE!" should hash the same (normalize drops punctuation + case).
        $a = DedupHasher::dedupHash('acc-1', '2026-04-15', -3457, 'EUR', 'DBIT', 'Pingo Doce', null);
        $b = DedupHasher::dedupHash('acc-1', '2026-04-15', -3457, 'EUR', 'DBIT', 'PINGO DOCE!', null);

        $this->assertSame($a, $b);
    }

    public function test_dedup_hash_different_on_different_entry_reference(): void
    {
        $a = DedupHasher::dedupHash('acc-1', '2026-04-15', -3457, 'EUR', 'DBIT', 'Pingo Doce', 'ref-a');
        $b = DedupHasher::dedupHash('acc-1', '2026-04-15', -3457, 'EUR', 'DBIT', 'Pingo Doce', 'ref-b');

        $this->assertNotSame($a, $b);
    }

    public function test_import_id_is_exactly_36_chars(): void
    {
        $id = DedupHasher::importId('acc-1', '2026-04-15', -3457, 'Pingo Doce', 1);

        $this->assertSame(36, strlen($id));
        $this->assertStringStartsWith('SPNDL:', $id);
    }

    public function test_import_id_changes_with_occurrence(): void
    {
        $a = DedupHasher::importId('acc-1', '2026-04-15', -3457, 'Pingo Doce', 1);
        $b = DedupHasher::importId('acc-1', '2026-04-15', -3457, 'Pingo Doce', 2);

        $this->assertNotSame($a, $b);
    }
}
