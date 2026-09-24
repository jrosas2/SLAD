<?php

namespace App\Concerns;

use App\Services\AuditoriaService;
use Illuminate\Database\Eloquent\Model;

trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn (Model $model): mixed => app(AuditoriaService::class)->created($model));
        static::updated(fn (Model $model): mixed => app(AuditoriaService::class)->updated($model));
        static::deleted(fn (Model $model): mixed => app(AuditoriaService::class)->deleted($model));
    }
}
