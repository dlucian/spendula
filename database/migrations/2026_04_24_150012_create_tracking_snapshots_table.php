<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bank_account_id')->constrained('bank_accounts')->cascadeOnDelete();
            $table->date('as_of_date');
            $table->bigInteger('native_balance_milliunits');
            $table->bigInteger('base_balance_milliunits');
            $table->decimal('exchange_rate', 18, 8);
            $table->string('exchange_rate_source');
            $table->string('ynab_transaction_id');
            $table->timestampTz('pushed_at');
            $table->timestamps();

            $table->index(['bank_account_id', 'as_of_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_snapshots');
    }
};
