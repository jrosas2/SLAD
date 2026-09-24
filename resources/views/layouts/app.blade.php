<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main class="slad-main min-h-screen text-[#091426] dark:text-slate-100">
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
