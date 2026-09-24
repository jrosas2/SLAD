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
        Schema::create('movimientos_financieros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('causa_id')->index()->constrained('causas')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('tipo', 10)->index();
            $table->decimal('monto', 15, 2);
            $table->date('fecha')->nullable()->index();
            $table->text('observacion')->nullable();
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
        Schema::dropIfExists('movimientos_financieros');
    }
};
