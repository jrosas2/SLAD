<?php

use App\Models\Ciudad;
use App\Models\Direccion;
use App\Models\EstadoCausa;
use App\Models\EstadoProcesal;
use App\Models\Juzgado;
use App\Models\Materia;
use App\Models\Submateria;
use Database\Seeders\CiudadSeeder;
use Database\Seeders\DireccionSeeder;
use Database\Seeders\EstadoCausaSeeder;
use Database\Seeders\EstadoProcesalSeeder;
use Database\Seeders\JuzgadoSeeder;
use Database\Seeders\MateriaSeeder;
use Database\Seeders\SubmateriaSeeder;

test('el seeder de submaterias carga las dependencias por materia sin duplicados', function () {
    $this->seed(MateriaSeeder::class);
    $civil = Materia::query()->where('nombre', 'CIVIL')->firstOrFail();
    Submateria::factory()->for($civil)->create([
        'nombre' => 'EXHORTO',
        'activo' => false,
    ]);

    $this->seed(SubmateriaSeeder::class);
    $this->seed(SubmateriaSeeder::class);

    expect(Submateria::query()->count())->toBe(50)
        ->and(Submateria::query()->whereDoesntHave('materia')->count())->toBe(0)
        ->and(Submateria::query()->where('nombre', 'OTRO')->count())->toBe(4)
        ->and(Submateria::query()->whereRelation('materia', 'nombre', 'LABORAL')->count())->toBe(8)
        ->and(Submateria::query()->whereRelation('materia', 'nombre', 'LABORAL')->where('nombre', 'MONITORIO')->count())->toBe(1)
        ->and(Submateria::query()->whereRelation('materia', 'nombre', 'CIVIL')->where('nombre', 'COBRANZA MUNICIPAL')->exists())->toBeTrue()
        ->and(Submateria::query()->whereRelation('materia', 'nombre', 'CIVIL')->where('nombre', 'EXHORTO')->value('activo'))->toBeTruthy()
        ->and(Submateria::query()->whereRelation('materia', 'nombre', 'TC')->where('nombre', 'INAPLICABILIDAD')->exists())->toBeTrue()
        ->and(Submateria::query()->whereRelation('materia', 'nombre', 'TA')->exists())->toBeFalse()
        ->and(Submateria::query()->whereRelation('materia', 'nombre', 'TCP')->exists())->toBeFalse();
});

test('el seeder de direcciones carga los valores normalizados y reactiva registros existentes', function () {
    Direccion::factory()->create([
        'nombre' => 'JURIDICA',
        'activo' => false,
    ]);

    $this->seed(DireccionSeeder::class);
    $this->seed(DireccionSeeder::class);

    expect(Direccion::query()->count())->toBe(26)
        ->and(Direccion::query()->where('nombre', 'JURIDICA')->value('activo'))->toBeTruthy()
        ->and(Direccion::query()->where('nombre', 'DES. COMUNITARIO')->exists())->toBeTrue()
        ->and(Direccion::query()->where('nombre', 'M. AMB; ASEO ORNATO')->exists())->toBeTrue()
        ->and(Direccion::query()->where('nombre', 'PERM. CIRCULACION')->exists())->toBeTrue();
});

test('el seeder de materias carga los valores únicos y reactiva registros existentes', function () {
    Materia::factory()->create([
        'nombre' => 'CIVIL',
        'activo' => false,
    ]);

    $this->seed(MateriaSeeder::class);
    $this->seed(MateriaSeeder::class);

    expect(Materia::query()->orderBy('nombre')->pluck('nombre')->all())->toBe([
        'CA',
        'CIVIL',
        'COBRANZA',
        'CS',
        'GRT',
        'JPL',
        'LABORAL',
        'OTRO',
        'TA',
        'TC',
        'TCP',
    ])->and(Materia::query()->where('nombre', 'CIVIL')->value('activo'))->toBeTruthy();
});

test('los seeders crean los estados iniciales sin duplicarlos', function () {
    $this->seed([
        EstadoCausaSeeder::class,
        EstadoProcesalSeeder::class,
    ]);
    $this->seed([
        EstadoCausaSeeder::class,
        EstadoProcesalSeeder::class,
    ]);

    expect(EstadoCausa::query()->orderBy('nombre')->pluck('nombre')->all())->toBe([
        'CERRADA',
        'VIGENTE',
    ])->and(EstadoProcesal::query()->count())->toBe(11)
        ->and(EstadoProcesal::query()->where('nombre', 'DISCUSIÓN')->value('activo'))->toBeTruthy();
});

test('el seeder de ciudades carga la lista completa sin duplicados y reactiva registros existentes', function () {
    Ciudad::factory()->create([
        'nombre' => 'SANTIAGO',
        'activo' => false,
    ]);

    $this->seed(CiudadSeeder::class);
    $this->seed(CiudadSeeder::class);

    expect(Ciudad::query()->count())->toBe(350)
        ->and(Ciudad::query()->where('nombre', 'SANTIAGO')->value('activo'))->toBeTruthy()
        ->and(Ciudad::query()->where('nombre', 'OLLAGÜE')->exists())->toBeTrue()
        ->and(Ciudad::query()->where('nombre', 'O’HIGGINS')->exists())->toBeTrue()
        ->and(Ciudad::query()->where('nombre', 'LA CALERA')->exists())->toBeTrue()
        ->and(Ciudad::query()->where('nombre', 'PUERTO AYSÉN')->exists())->toBeTrue()
        ->and(Ciudad::query()->where('nombre', 'PUERTO CISNES')->exists())->toBeTrue()
        ->and(Ciudad::query()->where('nombre', 'PUERTO NATALES')->exists())->toBeTrue()
        ->and(Ciudad::query()->where('nombre', 'PEÑAFLOR')->exists())->toBeTrue();
});

test('el seeder de juzgados carga el csv con sus ciudades sin duplicados', function () {
    $this->seed(JuzgadoSeeder::class);
    $this->seed(JuzgadoSeeder::class);

    expect(Ciudad::query()->count())->toBe(350)
        ->and(Juzgado::query()->count())->toBe(403)
        ->and(Juzgado::query()->whereDoesntHave('ciudad')->count())->toBe(0)
        ->and(Juzgado::query()
            ->whereRelation('ciudad', 'nombre', 'LA CALERA')
            ->where('nombre', 'Juzgado de Garantía de La Calera')
            ->exists())->toBeTrue()
        ->and(Juzgado::query()
            ->whereRelation('ciudad', 'nombre', 'PUERTO AYSÉN')
            ->where('nombre', 'Juzgado de Letras y Garantía de Puerto Aysén')
            ->exists())->toBeTrue()
        ->and(Juzgado::query()
            ->whereRelation('ciudad', 'nombre', 'PUERTO CISNES')
            ->where('nombre', 'Juzgado de Letras y Garantía de Puerto Cisnes')
            ->exists())->toBeTrue()
        ->and(Juzgado::query()
            ->whereRelation('ciudad', 'nombre', 'PUERTO NATALES')
            ->where('nombre', 'Juzgado de Letras y Garantía de Puerto Natales')
            ->exists())->toBeTrue();
});
