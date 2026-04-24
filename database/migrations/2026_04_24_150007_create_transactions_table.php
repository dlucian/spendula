<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bank_account_id')->constrained('bank_accounts')->cascadeOnDelete();
            $table->string('dedup_hash', 32);
            $table->string('entry_reference')->nullable();
            $table->string('status');
            $table->string('transaction_status');
            $table->date('booking_date');
            $table->date('value_date')->nullable();
            $table->bigInteger('amount_milliunits');
            $table->char('currency', 3);
            $table->string('credit_debit_indicator', 4);
            $table->string('counterparty_name')->nullable();
            $table->smallInteger('counterparty_resolution_level');
            $table->text('remittance_information')->nullable();
            $table->jsonb('raw_payload');
            $table->smallInteger('occurrence')->default(1);
            $table->string('ynab_transaction_id')->nullable();
            $table->string('ynab_import_id', 36)->nullable();
            $table->integer('push_attempt_count')->default(0);
            $table->timestampTz('last_push_attempt_at')->nullable();
            $table->text('last_push_error')->nullable();
            $table->timestampTz('pushed_at')->nullable();
            $table->timestampTz('skipped_at')->nullable();
            $table->text('skip_reason')->nullable();
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_updated_from_bank_at');
            $table->timestamps();

            $table->unique(['bank_account_id', 'dedup_hash', 'occurrence']);
            $table->index(['bank_account_id', 'entry_reference']);
            $table->index(['status']);
            $table->index(['bank_account_id', 'booking_date']);
        });

        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_status_check CHECK (status IN ('fetched', 'approved', 'skipped', 'transfer', 'pushed', 'tracking'))");
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_transaction_status_check CHECK (transaction_status IN ('BOOK', 'PDNG', 'INFO'))");
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_credit_debit_check CHECK (credit_debit_indicator IN ('CRDT', 'DBIT'))");
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_counterparty_resolution_check CHECK (counterparty_resolution_level BETWEEN 0 AND 4)');
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_occurrence_positive_check CHECK (occurrence >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
