<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payee_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('bank_slug');
            $table->string('counterparty_name');
            $table->string('action');
            $table->text('skip_reason')->nullable();
            $table->timestamps();

            $table->foreign('bank_slug')->references('slug')->on('banks')->cascadeOnUpdate();
            $table->unique(['bank_slug', 'counterparty_name']);
        });

        // CHECK constraints enforce two invariants the application also
        // upholds; the DB layer is the last line of defence against a
        // raw SQL slip or a future direct-write code path:
        //
        //   - action ∈ {approved, skipped, transfer}: the three terminal
        //     review verdicts. `pushed`/`fetched`/`tracking` are not valid
        //     auto-decision actions.
        //   - skip_reason is non-null only when action='skipped': the
        //     column is meaningful only for the skip path. A non-null
        //     skip_reason on an approve/transfer rule is corruption.
        DB::statement(
            'ALTER TABLE payee_rules ADD CONSTRAINT payee_rules_action_check '.
            "CHECK (action IN ('approved', 'skipped', 'transfer'))"
        );
        DB::statement(
            'ALTER TABLE payee_rules ADD CONSTRAINT payee_rules_skip_reason_only_when_skipped_check '.
            "CHECK (skip_reason IS NULL OR action = 'skipped')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('payee_rules');
    }
};
