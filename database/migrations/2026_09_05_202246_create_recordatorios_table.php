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
        Schema::create('recordatorios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('causa_id')->constrained('causas')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('titulo');
            $table->text('descripcion')->nullable();
            $table->dateTime('fecha_hora');
            $table->unsignedInteger('recordar_minutos_antes')->nullable();
            $table->dateTime('notificar_en')->index();
            $table->string('estado', 20)->default('PENDIENTE');
            $table->timestamp('notificado_at')->nullable();
            $table->timestamps();

            $table->index(['causa_id', 'fecha_hora']);
            $table->index(['user_id', 'estado', 'fecha_hora']);
            $table->index(['estado', 'notificado_at', 'notificar_en']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recordatorios');
    }
};
