<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('base_currency', 3);
            $table->char('quote_currency', 3);
            $table->date('rate_date');
            $table->decimal('rate', 18, 8);
            $table->string('source');
            $table->timestamps();

            $table->unique(['base_currency', 'quote_currency', 'rate_date', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
