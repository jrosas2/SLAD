<?php

use App\Support\ChileanRut;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('rut', 10)->nullable()->unique()->after('name');
            $table->string('telefono', 30)->nullable()->after('email');
        });

        DB::table('users')->select('id')->orderBy('id')->each(function (object $user): void {
            $body = (string) (99000000 + (int) $user->id);

            DB::table('users')->where('id', $user->id)->update([
                'rut' => ChileanRut::fromBody($body),
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['rut']);
            $table->dropColumn(['rut', 'telefono']);
        });
    }
};
