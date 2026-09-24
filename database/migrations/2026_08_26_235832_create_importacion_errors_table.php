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
        Schema::create('importacion_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('importacion_id')->index()->constrained('importaciones')->cascadeOnDelete();
            $table->unsignedInteger('fila')->nullable()->index();
            $table->string('numero_causa', 100)->nullable()->index();
            $table->string('campo', 100)->nullable();
            $table->text('valor_original')->nullable();
            $table->string('codigo_error', 100)->index();
            $table->text('mensaje');
            $table->json('datos_originales')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('importacion_errors');
    }
};
