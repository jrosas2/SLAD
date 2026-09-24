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
        Schema::create('actuaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('causa_id')->index()->constrained('causas')->cascadeOnUpdate()->cascadeOnDelete();
            $table->date('fecha')->nullable()->index();
            $table->foreignId('estado_procesal_id')->nullable()->index()->constrained('estados_procesales')->cascadeOnUpdate()->nullOnDelete();
            $table->text('descripcion');
            $table->foreignId('created_by')->nullable()->index()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index(['causa_id', 'fecha']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('actuaciones');
    }
};
