@php
    use App\Support\Format;
    $d = $this->data();
    $period = $d['period'];
    $daysLeft = $d['daysLeft'];
    $progress = $d['progress'];
@endphp

<x-filament::section>
    <x-slot name="heading">Periode payroll berjalan</x-slot>
    <x-slot name="description">{{ $period->label }}</x-slot>

    <div class="space-y-3">
        {{-- Satu baris SVG sederhana; responsif lewat viewBox, bukan lebar tetap. --}}
        <svg viewBox="0 0 100 12" preserveAspectRatio="none" class="h-6 w-full" role="img"
             aria-label="Hari ini berada {{ $progress }} persen menuju cut-off, {{ Format::sisaWaktu($daysLeft) }}">
            <line x1="1" y1="6" x2="99" y2="6" stroke="currentColor"
                  class="text-gray-300 dark:text-gray-600" stroke-width="1" stroke-linecap="round" />
            <line x1="1" y1="6" x2="{{ max(1, min(99, $progress)) }}" y2="6" stroke="currentColor"
                  class="text-primary-500" stroke-width="1" stroke-linecap="round" />
            <circle cx="1" cy="6" r="1.6" fill="currentColor" class="text-gray-400 dark:text-gray-500" />
            <circle cx="99" cy="6" r="1.6" fill="currentColor" class="text-gray-400 dark:text-gray-500" />
            <polygon points="{{ max(1, min(99, $progress)) }},2.6 {{ max(1, min(99, $progress)) + 2 }},6 {{ max(1, min(99, $progress)) }},9.4 {{ max(1, min(99, $progress)) - 2 }},6"
                     fill="currentColor" class="text-primary-600 dark:text-primary-400" />
        </svg>

        <div class="flex items-baseline justify-between text-sm">
            <span class="text-gray-600 dark:text-gray-400">
                {{ Format::tanggalRingkas($period->period_start) }}
            </span>
            <span @class([
                'font-medium',
                'text-rose-600 dark:text-rose-400' => $daysLeft <= 2,
                'text-amber-600 dark:text-amber-400' => $daysLeft > 2 && $daysLeft <= 7,
                'text-gray-600 dark:text-gray-400' => $daysLeft > 7,
            ])>
                cut-off {{ Format::sisaWaktu($daysLeft) }}
            </span>
            <span class="text-gray-600 dark:text-gray-400">
                {{ Format::tanggalRingkas($period->period_end) }}
            </span>
        </div>

        {{-- BR-11 / R-5 — mengingatkan sebelum cut-off, bukan sesudahnya. --}}
        @if ($d['unapproved'] > 0 && $daysLeft <= 7)
            <p class="flex items-start gap-1.5 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-400/10 dark:text-amber-300">
                <x-filament::icon icon="heroicon-m-clock" class="mt-0.5 h-4 w-4 shrink-0" />
                <span>Ada {{ $d['unapproved'] }} lembur yang belum kamu ajukan di periode ini.</span>
            </p>
        @endif
    </div>
</x-filament::section>
