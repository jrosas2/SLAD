<?php

use Livewire\Component;

new class extends Component
{
    public string $horaServidor = '';

    public string $fechaServidor = '';

    public function mount(): void
    {
        $this->actualizar();
    }

    public function actualizar(): void
    {
        $fechaHora = now()->timezone(config('app.timezone'));

        $this->horaServidor = $fechaHora->format('H:i');
        $this->fechaServidor = $fechaHora->format('d-m-Y');
    }
};
?>

<div wire:poll.30s="actualizar" class="grid gap-0.5 rounded-sm border border-white/10 bg-white/5 px-3 py-2 text-center text-slate-200">
    <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Hora del servidor</span>
    <time datetime="{{ $fechaServidor }}T{{ $horaServidor }}" class="font-mono text-lg font-semibold leading-none tabular-nums">{{ $horaServidor }}</time>
    <span class="text-[10px] text-slate-400">{{ $fechaServidor }} · Chile</span>
</div>
