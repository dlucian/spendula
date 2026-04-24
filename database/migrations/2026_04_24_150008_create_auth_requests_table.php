<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('state')->unique();
            $table->string('bank_slug');
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestamps();

            $table->foreign('bank_slug')->references('slug')->on('banks')->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_requests');
    }
};
