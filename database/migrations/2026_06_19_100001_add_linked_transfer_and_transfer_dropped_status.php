<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GH #16 — cross-source own-account top-up dedup.
 *
 * Adds:
 *   1. `transactions.linked_transfer_id` — nullable self-FK (nullOnDelete) that
 *      links the two legs of a cross-source card top-up pair. The funding-bank
 *      DBIT leg (status=transfer) points to the Revolut CRDT leg
 *      (status=transfer_dropped) and vice versa.
 *
 *   2. Extends the `transactions_status_check` CHECK constraint to include the
 *      new `transfer_dropped` terminal status. Because Postgres does not support
 *      ALTER CONSTRAINT inline, we drop and recreate the constraint.
 *
 * Rollback drops the column and restores the original CHECK constraint (excluding
 * transfer_dropped). Any transfer_dropped rows that exist at rollback time will
 * violate the restored constraint — the rollback is therefore safe only before
 * production data has been written in the new status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            // Self-FK: nullOnDelete so dropping one leg does not cascade-delete
            // the other. The linker never deletes rows — but defensive nullOnDelete
            // is the correct FK policy for a nullable self-referential link.
            $table->foreignUuid('linked_transfer_id')
                ->nullable()
                ->references('id')
                ->on('transactions')
                ->nullOnDelete()
                ->after('skip_reason');

            $table->index('linked_transfer_id');
        });

        // Extend the status CHECK constraint to include 'transfer_dropped'.
        // Must drop and recreate — Postgres has no ALTER CONSTRAINT for CHECK.
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT IF EXISTS transactions_status_check');
        DB::statement(
            "ALTER TABLE transactions ADD CONSTRAINT transactions_status_check CHECK (status IN ('fetched', 'approved', 'skipped', 'transfer', 'pushed', 'tracking', 'transfer_dropped'))",
        );
    }

    public function down(): void
    {
        // Restore the original CHECK constraint (without transfer_dropped).
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT IF EXISTS transactions_status_check');
        DB::statement(
            "ALTER TABLE transactions ADD CONSTRAINT transactions_status_check CHECK (status IN ('fetched', 'approved', 'skipped', 'transfer', 'pushed', 'tracking'))",
        );

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropForeign(['linked_transfer_id']);
            $table->dropIndex(['linked_transfer_id']);
            $table->dropColumn('linked_transfer_id');
        });
    }
};
