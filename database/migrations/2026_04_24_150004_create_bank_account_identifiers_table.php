<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_account_identifiers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUuid('bank_account_id')->constrained('bank_accounts')->cascadeOnDelete();
            $table->string('hash')->unique();
            $table->boolean('is_primary');
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestamps();

            $table->index('bank_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_account_identifiers');
    }
};
