<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Movimientos detectados en el Estado Diario del PJUD para causas de SLAD.
     * La columna "huella" evita notificar dos veces el mismo movimiento
     * aunque se consulte el tribunal varias veces en el día.
     */
    public function up(): void
    {
        Schema::create('pjud_movimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('causa_id')->constrained('causas')->cascadeOnUpdate()->cascadeOnDelete();
            $table->unsignedInteger('tribunal_pjud_codigo')->index();
            $table->date('fecha_estado_diario')->index();
            $table->string('numero_ingreso', 100);
            $table->string('tipo_coincidencia', 20)->default('exacta'); // exacta | compatible | parcial
            $table->string('tribunal_nombre', 180);
            $table->string('caratulado', 255)->nullable();
            $table->text('tramite')->nullable();
            $table->text('detalle')->nullable();
            $table->char('huella', 64)->unique();
            $table->string('google_calendar_event_id')->nullable()->unique();
            $table->timestamp('notificado_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pjud_movimientos');
    }
};
