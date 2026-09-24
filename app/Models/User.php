<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Support\ChileanRut;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string|null $rut
 * @property string|null $codigo
 * @property string $email
 * @property string|null $telefono
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property bool $activo
 * @property bool $debe_cambiar_password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'rut', 'codigo', 'email', 'telefono', 'password', 'activo', 'debe_cambiar_password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasFactory, HasRoles {
        checkPermissionTo as private checkPermissionIgnoringStatus;
    }

    use Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    protected $attributes = [
        'activo' => true,
        'debe_cambiar_password' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'activo' => 'boolean',
            'debe_cambiar_password' => 'boolean',
        ];
    }

    /** @return Attribute<string|null, string|null> */
    protected function rut(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => blank($value) ? null : ChileanRut::normalizeOrFail($value),
        );
    }

    public function rutFormateado(): ?string
    {
        return $this->rut === null ? null : ChileanRut::format($this->rut);
    }

    public function checkPermissionTo(string|int|Permission|\BackedEnum $permission, ?string $guardName = null): bool
    {
        return $this->activo && $this->checkPermissionIgnoringStatus($permission, $guardName);
    }

    /** @return HasMany<Actuacion, $this> */
    public function actuacionesCreadas(): HasMany
    {
        return $this->hasMany(Actuacion::class, 'created_by');
    }

    /** @return HasMany<MovimientoFinanciero, $this> */
    public function movimientosFinancierosCreados(): HasMany
    {
        return $this->hasMany(MovimientoFinanciero::class, 'created_by');
    }

    /** @return HasMany<Causa, $this> */
    public function causasAsignadas(): HasMany
    {
        return $this->hasMany(Causa::class, 'responsable_id');
    }

    /** @return HasMany<Importacion, $this> */
    public function importacionesEjecutadas(): HasMany
    {
        return $this->hasMany(Importacion::class);
    }

    /** @return HasMany<Recordatorio, $this> */
    public function recordatoriosAsignados(): HasMany
    {
        return $this->hasMany(Recordatorio::class, 'user_id');
    }

    /** @return HasMany<Recordatorio, $this> */
    public function recordatoriosCreados(): HasMany
    {
        return $this->hasMany(Recordatorio::class, 'created_by');
    }

    /** @param Builder<User> $query */
    public function scopeAsignableComoResponsable(Builder $query): void
    {
        $query->where('activo', true);
    }

    public function etiquetaResponsable(): string
    {
        return $this->codigo === null ? $this->name : $this->codigo.' - '.$this->name;
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
