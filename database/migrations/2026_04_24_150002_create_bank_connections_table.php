<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('bank_slug');
            $table->string('enable_banking_session_id');
            $table->string('status');
            $table->timestampTz('authorized_at');
            $table->timestampTz('valid_until');
            $table->uuid('superseded_by_id')->nullable();
            $table->jsonb('raw_session_response');
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestamps();

            $table->foreign('bank_slug')->references('slug')->on('banks')->cascadeOnUpdate();
            $table->index('bank_slug');
        });

        Schema::table('bank_connections', function (Blueprint $table) {
            $table->foreign('superseded_by_id')->references('id')->on('bank_connections')->nullOnDelete();
        });

        DB::statement("ALTER TABLE bank_connections ADD CONSTRAINT bank_connections_status_check CHECK (status IN ('active', 'superseded', 'expired', 'revoked', 'failed'))");

        // At most one active connection per bank at any time.
        DB::statement('CREATE UNIQUE INDEX bank_connections_one_active_per_bank ON bank_connections (bank_slug) WHERE status = \'active\'');
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_connections');
    }
};
