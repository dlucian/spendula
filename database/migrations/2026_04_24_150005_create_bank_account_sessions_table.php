<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_account_sessions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUuid('bank_connection_id')->constrained('bank_connections')->cascadeOnDelete();
            $table->foreignUuid('bank_account_id')->constrained('bank_accounts')->cascadeOnDelete();
            $table->string('enable_banking_uid');
            $table->timestamps();

            $table->unique(['bank_connection_id', 'bank_account_id']);
            $table->index('bank_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_account_sessions');
    }
};
