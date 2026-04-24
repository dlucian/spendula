<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->integer('transactions_pushed')->default(0);
            $table->integer('transactions_duplicate')->default(0);
            $table->integer('error_count')->default(0);
            $table->timestamps();

            $table->index('started_at');
        });

        Schema::create('push_run_errors', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('push_run_id')->constrained('push_runs')->cascadeOnDelete();
            $table->foreignUuid('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->string('error_type');
            $table->text('error_detail');
            $table->integer('http_status')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement("ALTER TABLE push_run_errors ADD CONSTRAINT push_run_errors_error_type_check CHECK (error_type IN ('validation', 'auth', 'rate_limit', 'http_error', 'network', 'other'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('push_run_errors');
        Schema::dropIfExists('push_runs');
    }
};
