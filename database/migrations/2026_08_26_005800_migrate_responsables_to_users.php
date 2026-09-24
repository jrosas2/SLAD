<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $responsableUserIds = [];

        DB::table('responsables')->orderBy('id')->each(function (object $responsable) use (&$responsableUserIds): void {
            $nombre = trim(implode(' ', array_filter([$responsable->nombres, $responsable->apellidos])));
            $codigo = blank($responsable->codigo) ? null : Str::upper(trim($responsable->codigo));

            $userId = $codigo === null
                ? null
                : DB::table('users')->where('codigo', $codigo)->value('id');

            if ($userId === null) {
                $matchingUsers = DB::table('users')
                    ->whereRaw('LOWER(name) = ?', [Str::lower($nombre)])
                    ->limit(2)
                    ->pluck('id');

                if ($matchingUsers->count() === 1) {
                    $userId = $matchingUsers->first();
                }
            }

            if ($userId === null) {
                $userId = DB::table('users')->insertGetId([
                    'name' => $nombre,
                    'codigo' => $codigo,
                    'email' => $this->historicalEmail((int) $responsable->id),
                    'email_verified_at' => null,
                    'password' => Hash::make(Str::random(40)),
                    'rol' => 'ABOGADO',
                    'activo' => false,
                    'debe_cambiar_password' => true,
                    'created_at' => $responsable->created_at ?? now(),
                    'updated_at' => now(),
                ]);
            } elseif ($codigo !== null && DB::table('users')->where('id', $userId)->value('codigo') === null) {
                DB::table('users')->where('id', $userId)->update(['codigo' => $codigo]);
            }

            $responsableUserIds[(int) $responsable->id] = (int) $userId;
        });

        Schema::table('causas', function (Blueprint $table) {
            $table->dropForeign(['responsable_id']);
        });

        foreach ($responsableUserIds as $responsableId => $userId) {
            DB::table('causas')->where('responsable_id', $responsableId)->update(['responsable_id' => $userId]);
        }

        Schema::table('causas', function (Blueprint $table) {
            $table->foreign('responsable_id')->references('id')->on('users')->cascadeOnUpdate()->nullOnDelete();
        });

        Schema::drop('responsables');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('responsables', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->nullable()->unique();
            $table->string('nombres', 120);
            $table->string('apellidos', 120)->nullable()->index();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::table('causas', function (Blueprint $table) {
            $table->dropForeign(['responsable_id']);
        });

        DB::table('causas')
            ->whereNotNull('responsable_id')
            ->distinct()
            ->pluck('responsable_id')
            ->each(function (int $userId): void {
                $user = DB::table('users')->where('id', $userId)->first();

                if ($user !== null) {
                    DB::table('responsables')->insert([
                        'id' => $user->id,
                        'codigo' => $user->codigo,
                        'nombres' => $user->name,
                        'apellidos' => null,
                        'activo' => $user->activo,
                        'created_at' => $user->created_at,
                        'updated_at' => now(),
                    ]);
                }
            });

        Schema::table('causas', function (Blueprint $table) {
            $table->foreign('responsable_id')->references('id')->on('responsables')->cascadeOnUpdate()->nullOnDelete();
        });
    }

    private function historicalEmail(int $responsableId): string
    {
        $sequence = 0;

        do {
            $suffix = $sequence === 0 ? '' : '-'.$sequence;
            $email = "responsable-historico-{$responsableId}{$suffix}@slad.invalid";
            $sequence++;
        } while (DB::table('users')->where('email', $email)->exists());

        return $email;
    }
};
