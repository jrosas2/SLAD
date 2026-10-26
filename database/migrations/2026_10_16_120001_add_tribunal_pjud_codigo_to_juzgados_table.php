<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vincula cada juzgado de SLAD con su código oficial del PJUD.
     */
    public function up(): void
    {
        Schema::table('juzgados', function (Blueprint $table) {
            $table->unsignedInteger('tribunal_pjud_codigo')->nullable()->after('nombre')->index();
            $table->foreign('tribunal_pjud_codigo')->references('codigo')->on('tribunales_pjud')->cascadeOnUpdate()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('juzgados', function (Blueprint $table) {
            $table->dropForeign(['tribunal_pjud_codigo']);
            $table->dropIndex(['tribunal_pjud_codigo']);
            $table->dropColumn('tribunal_pjud_codigo');
        });
    }
};
