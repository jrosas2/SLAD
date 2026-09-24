<?php

namespace App\Console\Commands;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\RolUsuario;
use App\Models\User;
use App\Rules\ValidChileanRut;
use App\Support\ChileanRut;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

#[Signature('slad:create-admin {rut? : RUT del administrador} {--email= : Correo electrónico del administrador} {--name= : Nombre completo del administrador}')]
#[Description('Crea el usuario administrador inicial de SLAD')]
class CreateAdminUser extends Command
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = (string) ($this->option('name') ?: $this->ask('Nombre completo'));
        $rutInput = (string) ($this->argument('rut') ?: $this->ask('R.U.T.'));
        $rut = ChileanRut::normalize($rutInput) ?? $rutInput;
        $email = Str::lower((string) ($this->option('email') ?: $this->ask('Correo electrónico')));
        $password = (string) $this->secret('Contraseña');
        $passwordConfirmation = (string) $this->secret('Confirmar contraseña');

        $validator = Validator::make([
            'name' => $name,
            'rut' => $rut,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
        ], [
            ...$this->profileRules(),
            'rut' => ['required', 'string', new ValidChileanRut, Rule::unique(User::class, 'rut')],
            'password' => $this->passwordRules(),
        ]);

        if ($validator->fails()) {
            $this->error('No se pudo crear el administrador:');

            foreach ($validator->errors()->all() as $error) {
                $this->line('- '.$error);
            }

            return self::FAILURE;
        }

        $user = User::query()->create([
            'name' => $name,
            'rut' => $rut,
            'email' => $email,
            'password' => $password,
            'activo' => true,
        ]);
        $user->assignRole(RolUsuario::Administrador->value);

        $user->markEmailAsVerified();

        $this->info("Administrador creado correctamente: {$email} ({$user->rutFormateado()})");

        return self::SUCCESS;
    }
}
