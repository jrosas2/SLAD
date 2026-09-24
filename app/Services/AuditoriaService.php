<?php

namespace App\Services;

use App\Enums\AccionAuditoria;
use App\Models\Auditoria;
use App\Models\Causa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;

class AuditoriaService
{
    /** @var list<string> */
    private const EXCLUDED_FIELDS = [
        'password', 'password_confirmation', 'remember_token', 'two_factor_secret',
        'two_factor_recovery_codes', 'token', 'secret', 'cookie', 'hash',
    ];

    public function created(Model $model): void
    {
        $this->record($model, AccionAuditoria::Creado, null, $model->getAttributes());
    }

    public function updated(Model $model): void
    {
        $changes = $this->withoutTimestamps($model->getChanges());

        if ($changes === []) {
            return;
        }

        $this->record($model, AccionAuditoria::Modificado, Arr::only($model->getOriginal(), array_keys($changes)), $changes);
    }

    public function deleted(Model $model): void
    {
        $this->record($model, AccionAuditoria::Eliminado, $model->getAttributes(), null);
    }

    /** @param array<string, mixed>|null $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(Model $model, AccionAuditoria $action, ?array $before, ?array $after): void
    {
        $request = request();
        $actor = Auth::user();

        Auditoria::query()->create([
            'user_id' => $actor?->getAuthIdentifier(),
            'accion' => $action,
            'modelo' => $model::class,
            'modelo_id' => $model->getKey(),
            'causa_id' => $this->resolveCausaId($model),
            'valores_anteriores' => $this->sanitize($before),
            'valores_nuevos' => $this->sanitize($after),
            'ip_address' => $request instanceof Request ? $request->ip() : null,
            'user_agent' => $request instanceof Request ? $request->userAgent() : null,
        ]);
    }

    /** @param array<string, mixed>|null $values
     * @return array<string, mixed>|null
     */
    private function sanitize(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        return collect($values)
            ->reject(fn (mixed $value, string|int $key): bool => $this->isSensitive((string) $key))
            ->map(fn (mixed $value): mixed => is_array($value) ? $this->sanitize($value) : $value)
            ->all();
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function withoutTimestamps(array $values): array
    {
        return Arr::except($values, ['created_at', 'updated_at']);
    }

    private function isSensitive(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::EXCLUDED_FIELDS as $field) {
            if ($normalized === $field || str_contains($normalized, $field)) {
                return true;
            }
        }

        return false;
    }

    private function resolveCausaId(Model $model): ?int
    {
        if ($model instanceof Causa) {
            return (int) $model->getKey();
        }

        $causaId = $model->getAttribute('causa_id');

        return $causaId === null ? null : (int) $causaId;
    }
}
