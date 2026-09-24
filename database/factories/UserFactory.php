<?php

namespace Database\Factories;

use App\Enums\RolUsuario;
use App\Models\User;
use App\Support\ChileanRut;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'rut' => ChileanRut::fromBody((string) fake()->unique()->numberBetween(90000000, 98999999)),
            'codigo' => null,
            'email' => fake()->unique()->safeEmail(),
            'telefono' => fake()->optional()->numerify('+56 9 #### ####'),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'activo' => true,
            'debe_cambiar_password' => false,
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            if (! $user->roles()->exists()) {
                $user->assignRole(RolUsuario::Consulta->value);
            }
        });
    }

    public function administrador(): static
    {
        return $this->afterCreating(
            fn (User $user) => $user->syncRoles([RolUsuario::Administrador->value]),
        );
    }

    public function abogado(): static
    {
        return $this->afterCreating(
            fn (User $user) => $user->syncRoles([RolUsuario::Abogado->value]),
        );
    }

    public function consulta(): static
    {
        return $this->afterCreating(
            fn (User $user) => $user->syncRoles([RolUsuario::Consulta->value]),
        );
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the user must replace a temporary password.
     */
    public function withTemporaryPassword(): static
    {
        return $this->state(fn (array $attributes) => [
            'debe_cambiar_password' => true,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
