<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\RolUsuario;
use App\Models\User;
use App\Rules\ValidChileanRut;
use App\Support\ChileanRut;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $input['rut'] = ChileanRut::normalize($input['rut']) ?? $input['rut'];

        Validator::make($input, [
            ...$this->profileRules(),
            'rut' => ['required', 'string', new ValidChileanRut, 'unique:users,rut'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'password' => $this->passwordRules(),
        ])->validate();

        $user = User::create([
            'name' => $input['name'],
            'rut' => $input['rut'],
            'email' => $input['email'],
            'telefono' => $input['telefono'] ?? null,
            'password' => $input['password'],
        ]);

        $user->assignRole(RolUsuario::Consulta->value);

        return $user;
    }
}
