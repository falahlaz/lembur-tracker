@props(['icon' => 'heroicon-o-inbox', 'heading' => '', 'description' => null])

{{-- Design Brief §10 — seluruh empty state ditulis sendiri. Ruang kosong yang
     menjelaskan langkah berikutnya jauh lebih berguna daripada "No records found". --}}
<div {{ $attributes->merge(['class' => 'flex flex-col items-center gap-2 rounded-xl border border-dashed border-gray-300 px-6 py-12 text-center dark:border-white/10']) }}>
    <x-filament::icon :icon="$icon" class="h-8 w-8 text-gray-400 dark:text-gray-500" />

    <p class="text-base font-medium text-gray-950 dark:text-white">{{ $heading }}</p>

    @if ($description)
        <p class="max-w-sm text-sm text-gray-500 dark:text-gray-400">{{ $description }}</p>
    @endif

    @if (! $slot->isEmpty())
        <div class="mt-2">{{ $slot }}</div>
    @endif
</div>
