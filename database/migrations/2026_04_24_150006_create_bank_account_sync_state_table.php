<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_account_sync_state', function (Blueprint $table) {
            $table->foreignUuid('bank_account_id')->primary()->constrained('bank_accounts')->cascadeOnDelete();
            $table->timestampTz('last_successful_sync_at')->nullable();
            $table->date('last_fetched_through')->nullable();
            $table->text('last_continuation_key')->nullable();
            $table->timestampTz('last_sync_error_at')->nullable();
            $table->integer('consecutive_failure_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_account_sync_state');
    }
};
