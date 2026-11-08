<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Causas de SLAD detectadas en la programación de salas (tablas) de las
     * Cortes. La "huella" (causa + Corte + tipo de tabla + fecha + sala +
     * ingreso) evita avisar dos veces la misma vista; si la causa cambia de
     * fecha o de sala, es una huella nueva y se avisa de nuevo.
     */
    public function up(): void
    {
        Schema::create('pjud_programaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('causa_id')->constrained('causas')->cascadeOnUpdate()->cascadeOnDelete();
            $table->unsignedInteger('tribunal_pjud_codigo')->index();
            $table->string('tribunal_nombre', 180);
            $table->string('tipo_tabla_codigo', 10);
            $table->string('tipo_tabla', 80);
            $table->date('fecha_vista')->index();
            $table->string('sala_numero', 10);
            $table->string('sala', 80);
            $table->string('relator', 180)->nullable();
            $table->unsignedSmallInteger('numero_orden')->nullable();
            $table->string('numero_ingreso', 100);
            $table->string('tipo_coincidencia', 20)->default('exacta'); // exacta | compatible | parcial
            $table->string('caratulado', 255)->nullable();
            $table->char('huella', 64)->unique();
            $table->timestamp('detectada_en')->nullable(); // primera vez que apareció en la tabla del PJUD
            $table->string('google_calendar_event_id')->nullable()->unique();
            $table->timestamp('notificado_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pjud_programaciones');
    }
};
