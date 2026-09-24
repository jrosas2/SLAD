<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('causas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->date('fecha_causa')->nullable()->index();
            $table->date('fecha_ingreso')->nullable()->index();
            $table->foreignId('juzgado_id')->nullable()->index()->constrained('juzgados')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('materia_id')->index()->constrained('materias')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('submateria_id')->nullable()->index()->constrained('submaterias')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('estado_procesal_id')->nullable()->index()->constrained('estados_procesales')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('direccion_id')->nullable()->index()->constrained('direcciones')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('responsable_id')->nullable()->index()->constrained('responsables')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('accion_id')->nullable()->index()->constrained('acciones')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('estado_causa_id')->nullable()->index()->constrained('estados_causa')->cascadeOnUpdate()->nullOnDelete();
            $table->string('numero_causa', 100)->nullable()->index();
            $table->decimal('monto_demandado', 15, 2)->nullable();
            $table->text('observacion_importante')->nullable();
            $table->boolean('tiene_cotizaciones')->nullable();
            $table->string('funcionario_usuario')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('causas');
    }
};
