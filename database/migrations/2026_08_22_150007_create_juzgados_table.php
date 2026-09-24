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
        Schema::create('juzgados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ciudad_id')->index()->constrained('ciudades')->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedSmallInteger('numero')->nullable();
            $table->string('nombre', 180);
            $table->string('tipo', 100)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['ciudad_id', 'nombre']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('juzgados');
    }
};
