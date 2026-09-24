@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand name="SLAD" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-sm bg-[#006a61] text-white">
            <flux:icon.scale variant="solid" class="size-5 text-white" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand name="SLAD" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-sm bg-[#006a61] text-white">
            <flux:icon.scale variant="solid" class="size-5 text-white" />
        </x-slot>
    </flux:brand>
@endif
