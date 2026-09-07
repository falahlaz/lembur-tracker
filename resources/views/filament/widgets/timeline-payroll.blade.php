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
        {{-- Track div, bukan SVG. Versi SVG memakai preserveAspectRatio="none"
             pada viewBox 100x12, jadi skala X dan Y berbeda jauh dan penanda
             bulatnya keluar sebagai elips gepeng. Div tidak bisa terdistorsi. --}}
        @php $pos = max(1, min(99, $progress)); @endphp
        <div class="relative h-6" role="img"
             aria-label="Hari ini berada {{ $progress }} persen menuju cut-off, {{ Format::sisaWaktu($daysLeft) }}">
            <div class="absolute inset-x-0 top-1/2 h-1.5 -translate-y-1/2 rounded-full bg-gray-200 dark:bg-white/10"></div>

            <div class="absolute start-0 top-1/2 h-1.5 -translate-y-1/2 rounded-full bg-primary-500"
                 style="width: {{ $pos }}%"></div>

            {{-- Penanda hari ini; ring memisahkannya dari bar di belakangnya. --}}
            <div class="absolute top-1/2 size-3 -translate-x-1/2 -translate-y-1/2 rounded-full bg-primary-600 ring-2 ring-white dark:bg-primary-400 dark:ring-gray-900"
                 style="inset-inline-start: {{ $pos }}%"></div>
        </div>

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
