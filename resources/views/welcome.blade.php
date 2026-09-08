<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => 'Inicio'])
        <meta name="description" content="SLAD centraliza la administración y trazabilidad de causas jurídicas." />
    </head>
    <body class="min-h-screen bg-[#f8f9ff] text-[#091426] antialiased dark:bg-[#08111f] dark:text-slate-100">
        <div class="flex min-h-screen flex-col">
            <header class="fixed inset-x-0 top-0 z-50 border-b border-[#c5c6cd] bg-[#f8f9ff]/95 dark:border-slate-700 dark:bg-[#08111f]/95">
                <div class="mx-auto flex h-16 w-full max-w-7xl items-center justify-between px-5 sm:px-8 lg:px-10">
                    <a href="{{ route('home') }}" class="text-xl font-bold tracking-tight" aria-label="SLAD, página de inicio">
                        SLAD
                    </a>

                    @auth
                        <a href="{{ route('dashboard') }}" class="text-sm font-semibold transition-colors hover:text-[#006a61] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#006a61] dark:hover:text-teal-300">
                            Ir al panel
                        </a>
                    @else
                        <a href="{{ route('login') }}" class="text-sm font-semibold transition-colors hover:text-[#006a61] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#006a61] dark:hover:text-teal-300">
                            Ingresar
                        </a>
                    @endauth
                </div>
            </header>

            <main class="flex-1 pt-16 pb-32 sm:pb-24">
                <section class="border-b border-[#c5c6cd] dark:border-slate-700">
                    <div class="mx-auto grid w-full max-w-7xl items-center gap-12 px-5 py-16 sm:px-8 sm:py-20 lg:grid-cols-2 lg:px-10 lg:py-24">
                        <div class="max-w-xl">
                            <div class="mb-6 inline-flex items-center gap-2 rounded border border-[#a9b9cc] bg-[#e4efff] px-3 py-1.5 text-xs font-semibold tracking-wide text-[#42566f] dark:border-teal-900 dark:bg-teal-950/50 dark:text-teal-200">
                                <flux:icon.code-bracket class="size-4 text-[#006a61] dark:text-teal-300" />
                                Plataforma de Administración y Control
                            </div>

                            <h1 class="text-4xl font-bold leading-tight tracking-tight text-balance sm:text-5xl lg:text-[3.5rem]">
                                Sistema de Gestión de Causas
                            </h1>

                            <p class="mt-5 max-w-lg text-base leading-7 text-[#505866] sm:text-lg dark:text-slate-300">
                                Una plataforma para administrar causas, actuaciones, responsables y movimientos financieros desde un espacio de trabajo centralizado y trazable.
                            </p>
                        </div>

                        <div class="overflow-hidden rounded border border-[#c5c6cd] bg-white shadow-[0_10px_24px_rgba(9,20,38,0.06)] dark:border-slate-700 dark:bg-slate-900">
                            <img
                                src="https://lh3.googleusercontent.com/aida-public/AB6AXuDuwcA5jrOKeJpgahD-mjJR4LtHENDRsTOKMbvTSy07AFTUp0GbJRHsfqtAQ6Bc5yAA92RM_dD8wGmwh2ttdATGeSbyWyM7eYpmHaM2v8IAIiX8TnhBNxXt2XGzNLRQVUJtFmicyjLMCG978RFjC55JNZBVIOTrRVPCFWjm3sQ_yHIVtkjDQnHXxmHMeeXtYpA5ciiWX9wwqOP1dmE_xw6-grBxCpfVb_kpo_jT25Vnhu_qViXczLz5GA"
                                alt="Mapa mundial conectado que representa la coordinación de procesos legales"
                                class="aspect-[4/3] size-full object-cover"
                                fetchpriority="high"
                            />
                        </div>
                    </div>
                </section>

                <section id="capacidades" class="bg-white dark:bg-[#0b1728]">
                    <div class="mx-auto w-full max-w-7xl px-5 py-16 sm:px-8 lg:px-10 lg:py-20">
                        <h2 class="text-2xl font-bold tracking-tight sm:text-3xl">Infraestructura Funcional</h2>

                        <div class="mt-3 grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
                            <article class="border border-[#c5c6cd] bg-[#f8f9ff] p-6 dark:border-slate-700 dark:bg-slate-900">
                                <span class="flex size-10 items-center justify-center rounded-sm bg-[#d9e8ff] text-[#091426] dark:bg-teal-950 dark:text-teal-200">
                                    <flux:icon.shield-check variant="solid" class="size-5" />
                                </span>
                                <h3 class="mt-5 text-xl font-bold">Seguridad y roles</h3>
                                <p class="mt-2 text-sm leading-6 text-[#505866] dark:text-slate-300">
                                    Accesos diferenciados y administración de usuarios según las responsabilidades de cada equipo.
                                </p>
                            </article>

                            <article class="border border-[#c5c6cd] bg-[#f8f9ff] p-6 dark:border-slate-700 dark:bg-slate-900">
                                <span class="flex size-10 items-center justify-center rounded-sm bg-[#d9e8ff] text-[#091426] dark:bg-teal-950 dark:text-teal-200">
                                    <flux:icon.briefcase variant="solid" class="size-5" />
                                </span>
                                <h3 class="mt-5 text-xl font-bold">Expedientes normalizados</h3>
                                <p class="mt-2 text-sm leading-6 text-[#505866] dark:text-slate-300">
                                    Causas, materias, tribunales y responsables organizados bajo una estructura común.
                                </p>
                            </article>

                            <article class="border border-[#c5c6cd] bg-[#f8f9ff] p-6 dark:border-slate-700 dark:bg-slate-900">
                                <span class="flex size-10 items-center justify-center rounded-sm bg-[#d9e8ff] text-[#091426] dark:bg-teal-950 dark:text-teal-200">
                                    <flux:icon.rectangle-stack variant="solid" class="size-5" />
                                </span>
                                <h3 class="mt-5 text-xl font-bold">Trazabilidad procesal</h3>
                                <p class="mt-2 text-sm leading-6 text-[#505866] dark:text-slate-300">
                                    Seguimiento cronológico de actuaciones, estados e hitos vinculados a cada causa.
                                </p>
                            </article>

                            <article class="border border-[#c5c6cd] bg-[#f8f9ff] p-6 dark:border-slate-700 dark:bg-slate-900">
                                <span class="flex size-10 items-center justify-center rounded-sm bg-[#d9e8ff] text-[#091426] dark:bg-teal-950 dark:text-teal-200">
                                    <flux:icon.banknotes variant="solid" class="size-5" />
                                </span>
                                <h3 class="mt-5 text-xl font-bold">Control financiero</h3>
                                <p class="mt-2 text-sm leading-6 text-[#505866] dark:text-slate-300">
                                    Registro de ingresos y egresos asociados al expediente para conservar su contexto.
                                </p>
                            </article>
                        </div>
                    </div>
                </section>
            </main>

            <footer class="fixed inset-x-0 bottom-0 z-50 bg-[#1f2b3de7] text-slate-200">
                <div class="mx-auto flex w-full max-w-7xl flex-col gap-3 px-5 py-8 text-sm sm:flex-row sm:items-center sm:justify-between sm:px-8 lg:px-10">
                    <p class="text-lg font-bold">SLAD</p>
                    <p class="text-slate-200">© {{ now()->year }} SLAD · Sistema Logístico de Administración de Derecho.</p>
                </div>
            </footer>
        </div>

        @fluxScripts
    </body>
</html>
