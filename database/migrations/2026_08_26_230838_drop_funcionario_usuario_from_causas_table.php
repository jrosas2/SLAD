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
        Schema::table('causas', function (Blueprint $table) {
            $table->dropColumn('funcionario_usuario');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('causas', function (Blueprint $table) {
            $table->string('funcionario_usuario')->nullable();
        });
    }
};
