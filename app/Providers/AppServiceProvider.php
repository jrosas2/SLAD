<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Pjud\EstadoDiarioHtmlParser;
use App\Services\Pjud\EstadoDiarioPjudClient;
use App\Services\Pjud\FuenteEstadoDiario;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(FuenteEstadoDiario::class, EstadoDiarioPjudClient::class);
        $this->app->bind(EstadoDiarioHtmlParser::class, fn (): EstadoDiarioHtmlParser => new EstadoDiarioHtmlParser(
            (array) config('pjud.estado_diario.columnas', []),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        Model::preventLazyLoading(app()->isLocal());
        Model::preventSilentlyDiscardingAttributes(app()->isLocal());

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    private function configureAuthorization(): void
    {
        Gate::before(fn (User $user): ?bool => $user->activo ? null : false);
    }
}
