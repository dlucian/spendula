<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('bank_slug')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->integer('transactions_inserted')->default(0);
            $table->integer('transactions_updated')->default(0);
            $table->integer('transactions_deduped')->default(0);
            $table->integer('error_count')->default(0);
            $table->timestamps();

            $table->foreign('bank_slug')->references('slug')->on('banks')->cascadeOnUpdate();
            $table->index('started_at');
        });

        Schema::create('sync_run_errors', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('sync_run_id')->constrained('sync_runs')->cascadeOnDelete();
            $table->foreignUuid('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->string('error_type');
            $table->text('error_detail');
            $table->integer('http_status')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement("ALTER TABLE sync_run_errors ADD CONSTRAINT sync_run_errors_error_type_check CHECK (error_type IN ('consent_expired', 'rate_limit', 'http_error', 'parse_error', 'conversion_error', 'other'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_run_errors');
        Schema::dropIfExists('sync_runs');
    }
};
