@php
    use App\Support\Format;
    /** @var \App\Domain\Lembur\EntitlementPreview $preview */
@endphp

<div class="space-y-3 text-sm" aria-live="polite">
    {{-- Durasi selalu tampil, memenuhi tier atau tidak. --}}
    <dl class="grid grid-cols-2 gap-x-4 gap-y-2">
        <dt class="text-gray-500 dark:text-gray-400">Durasi</dt>
        <dd class="font-medium tabular-nums text-gray-950 dark:text-white">
            {{ Format::durasi($preview->effectiveMinutes) }}
        </dd>

        @if ($preview->dailyTotalMinutes !== $preview->effectiveMinutes)
            {{-- BR-02 — ada sesi lain di tanggal yang sama. --}}
            <dt class="text-gray-500 dark:text-gray-400">Total hari ini</dt>
            <dd class="font-medium tabular-nums text-gray-950 dark:text-white">
                {{ Format::durasi($preview->dailyTotalMinutes) }}
                <span class="text-xs text-gray-500">termasuk sesi lain di tanggal ini</span>
            </dd>
        @endif

        @if ($preview->qualifies())
            <dt class="text-gray-500 dark:text-gray-400">Tier</dt>
            <dd class="font-medium text-gray-950 dark:text-white">{{ $preview->tier->getLabel() }}</dd>

            <dt class="text-gray-500 dark:text-gray-400">Uang makan</dt>
            <dd class="font-semibold tabular-nums text-emerald-700 dark:text-emerald-400">
                {{ Format::rupiah($preview->mealAmount) }}
            </dd>

            <dt class="text-gray-500 dark:text-gray-400">Cuti pengganti</dt>
            <dd class="font-medium tabular-nums text-gray-950 dark:text-white">
                {{ Format::durasi($preview->leaveMinutes) }}
                <span class="block text-xs text-gray-500">({{ $preview->leaveHumanised() }})</span>
            </dd>

            <dt class="text-gray-500 dark:text-gray-400">Berlaku s/d</dt>
            <dd class="font-medium text-gray-950 dark:text-white">
                {{ Format::tanggalPanjang($preview->expiresAt) }}
            </dd>
        @endif

        <dt class="text-gray-500 dark:text-gray-400">Payroll</dt>
        <dd class="font-medium text-gray-950 dark:text-white">periode {{ $preview->payrollLabel }}</dd>
    </dl>

    {{-- Design Brief §4.2 — nada netral-informatif, bukan error merah.
         User tidak salah; mereka hanya belum memenuhi ambang. --}}
    @if (! $preview->qualifies())
        <p class="rounded-lg bg-gray-100 px-3 py-2 text-gray-700 dark:bg-white/5 dark:text-gray-300">
            {{ $preview->shortfallMessage() }}
        </p>
    @endif

    {{-- BR-04 — pembulatan tidak boleh terjadi diam-diam. --}}
    @if ($preview->roundingApplied)
        <p class="flex items-start gap-1.5 text-xs text-gray-600 dark:text-gray-400">
            <x-filament::icon icon="heroicon-m-arrows-right-left" class="mt-0.5 h-4 w-4 shrink-0" />
            <span>{{ $preview->roundingMessage() }}</span>
        </p>
    @endif

    {{-- BR-11 — memperingatkan, tidak memblokir. Keputusan akhir di HRD. --}}
    @if ($preview->pastCutoff)
        <p class="flex items-start gap-1.5 rounded-lg bg-amber-50 px-3 py-2 text-amber-800 dark:bg-amber-400/10 dark:text-amber-300">
            <x-filament::icon icon="heroicon-m-exclamation-triangle" class="mt-0.5 h-4 w-4 shrink-0" />
            <span>Berisiko melewati cut-off — periode {{ $preview->payrollLabel }} sudah ditutup.
                Catatannya tetap bisa disimpan, tapi konfirmasikan ke HRD.</span>
        </p>
    @endif

    {{-- P-5 / R-1 — jujur soal batasnya, di setiap layar yang menampilkan nominal. --}}
    <p class="text-xs text-gray-500 dark:text-gray-400">
        Angka ini estimasi berdasarkan catatanmu, bukan perhitungan payroll resmi.
    </p>
</div>
