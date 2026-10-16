<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catálogo oficial de tribunales del Poder Judicial (códigos PJUD).
     */
    public function up(): void
    {
        Schema::create('tribunales_pjud', function (Blueprint $table) {
            $table->unsignedInteger('codigo')->primary();
            $table->unsignedSmallInteger('tipo_juzgado')->index();
            $table->string('nombre', 180);
            $table->string('nombre_normalizado', 180)->index();
            $table->string('corte_apelaciones', 120)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tribunales_pjud');
    }
};
