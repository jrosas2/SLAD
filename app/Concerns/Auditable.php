<?php

namespace App\Concerns;

use App\Services\AuditoriaService;
use Illuminate\Database\Eloquent\Model;

trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model): void {
            app(AuditoriaService::class)->created($model);
        });
        static::updated(function (Model $model): void {
            app(AuditoriaService::class)->updated($model);
        });
        static::deleted(function (Model $model): void {
            app(AuditoriaService::class)->deleted($model);
        });
    }
}
