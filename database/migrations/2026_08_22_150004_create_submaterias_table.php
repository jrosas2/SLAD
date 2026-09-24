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
        Schema::create('submaterias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('materia_id')->index()->constrained('materias')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('nombre', 180);
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['materia_id', 'nombre']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('submaterias');
    }
};
