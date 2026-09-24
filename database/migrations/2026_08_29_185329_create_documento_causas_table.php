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
        Schema::create('documentos_causa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('causa_id')->constrained('causas')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nombre_original');
            $table->string('nombre_archivo');
            $table->string('ruta');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('tamano_original');
            $table->unsignedBigInteger('tamano_almacenado');
            $table->boolean('comprimido')->default(false);
            $table->char('hash_sha256', 64)->nullable();
            $table->text('descripcion')->nullable();
            $table->timestamps();
            $table->index('causa_id');
            $table->index('user_id');
            $table->index('hash_sha256');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documentos_causa');
    }
};
