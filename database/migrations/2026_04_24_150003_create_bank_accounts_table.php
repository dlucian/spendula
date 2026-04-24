<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('bank_slug');
            $table->string('display_name')->nullable();
            $table->string('iban')->nullable();
            $table->char('currency', 3);
            $table->boolean('is_base_currency');
            $table->uuid('ynab_account_id')->nullable();
            $table->string('ynab_account_type')->nullable();
            $table->date('import_cutoff_date')->nullable();
            $table->boolean('active')->default(true);
            $table->timestampTz('first_linked_at');
            $table->timestampTz('last_seen_at');
            $table->timestamps();

            $table->foreign('bank_slug')->references('slug')->on('banks')->cascadeOnUpdate();
            $table->index('bank_slug');
        });

        DB::statement("ALTER TABLE bank_accounts ADD CONSTRAINT bank_accounts_ynab_account_type_check CHECK (ynab_account_type IS NULL OR ynab_account_type IN ('on_budget', 'tracking'))");

        // SPEC §4.3: non-base-currency accounts can only map to tracking.
        DB::statement("ALTER TABLE bank_accounts ADD CONSTRAINT bank_accounts_currency_mapping_check CHECK (is_base_currency OR ynab_account_type IS NULL OR ynab_account_type = 'tracking')");
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
