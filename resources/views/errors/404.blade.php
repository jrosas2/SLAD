<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => 'Página no encontrada'])
        <meta name="robots" content="noindex" />
    </head>
    <body class="min-h-screen bg-[#f8f9ff] text-[#091426] antialiased dark:bg-[#08111f] dark:text-slate-100">
        <main class="mx-auto flex min-h-screen w-full max-w-6xl flex-col items-center justify-center gap-4 px-4 py-8 sm:px-8">
            <a href="{{ route('home') }}" class="self-start text-sm font-semibold text-[#006a61] transition-colors hover:text-[#004b45] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#006a61] dark:text-teal-300 dark:hover:text-teal-200">
                ← Volver al inicio
            </a>

            <section class="w-full overflow-hidden rounded-2xl border border-[#c5c6cd] bg-white shadow-[0_10px_24px_rgba(9,20,38,0.08)] dark:border-slate-700 dark:bg-slate-900" aria-labelledby="error-title">
                <img
                    src="{{ asset('images/errors/zoro-404.png') }}"
                    alt="Zoro perdido frente a una página 404"
                    class="mx-auto block max-h-[76vh] w-full object-contain"
                    fetchpriority="high"
                />
                <div class="sr-only">
                    <h1 id="error-title">Página no encontrada</h1>
                    <p>La página solicitada no existe o fue movida.</p>
                </div>
            </section>

            <a href="{{ route('home') }}" class="inline-flex items-center gap-2 rounded-lg bg-[#006a61] px-5 py-3 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-[#004b45] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#006a61]">
                <flux:icon.home class="size-5" />
                Volver al inicio
            </a>
        </main>

        @fluxScripts
    </body>
</html>
