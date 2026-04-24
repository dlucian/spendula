<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banks', function (Blueprint $table) {
            $table->string('slug')->primary();
            $table->string('display_name');
            $table->string('aspsp_name');
            $table->char('aspsp_country', 2);
            $table->string('psu_type');
            $table->char('default_currency', 3);
            $table->integer('sync_lookback_days');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        DB::statement("ALTER TABLE banks ADD CONSTRAINT banks_psu_type_check CHECK (psu_type IN ('personal', 'business'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('banks');
    }
};
