@php
    use App\Support\Format;
    /** @var \App\Domain\Lembur\AllocationPlan $plan */
    /** @var \App\Domain\Lembur\ClaimValidationResult $validation */
    $quotaAfter = $quotaUsed + ($plan->requiredMinutes >= 480 ? 1.0 : 0.5);
    $fmtQuota = fn (float $v) => rtrim(rtrim(number_format($v, 1, ',', '.'), '0'), ',');
@endphp

<div class="space-y-3 text-sm" aria-live="polite">
    @if ($validation->failed())
        {{-- Design Brief §4.4 — error muncul DI DALAM panel, bukan sebagai toast,
             dan selalu menyebutkan jalan keluarnya. --}}
        <div class="flex items-start gap-2 rounded-lg bg-rose-50 px-3 py-2.5 text-rose-800 dark:bg-rose-400/10 dark:text-rose-300">
            <x-filament::icon icon="heroicon-m-exclamation-circle" class="mt-0.5 h-4 w-4 shrink-0" />
            <span>{{ $validation->message }}</span>
        </div>
    @endif

    @if (count($plan->slices) > 0)
        <ul class="divide-y divide-gray-200 dark:divide-white/10">
            @foreach ($plan->slices as $slice)
                <li class="flex items-baseline justify-between gap-3 py-2">
                    <span class="font-medium tabular-nums text-gray-950 dark:text-white">
                        {{ Format::durasiRingkas($slice->minutes) }}
                    </span>
                    <span class="flex-1 text-gray-600 dark:text-gray-400">
                        dari lembur {{ Format::tanggalRingkas($slice->balance->earned_date) }}
                        <span class="text-xs text-gray-500">
                            · hangus {{ Format::tanggalRingkas($slice->balance->expires_at) }}
                        </span>
                    </span>
                    <span class="text-xs {{ $slice->exhaustsBatch() ? 'text-gray-500' : 'text-emerald-700 dark:text-emerald-400' }}">
                        {{ $slice->exhaustsBatch() ? 'habis' : 'sisa '.Format::durasiRingkas($slice->balanceRemainingAfter) }}
                    </span>
                </li>
            @endforeach
        </ul>

        <div class="flex items-baseline justify-between border-t border-gray-200 pt-2 dark:border-white/10">
            <span class="font-medium text-gray-950 dark:text-white">
                Total {{ Format::durasi($plan->requiredMinutes) }}
            </span>
            <span class="tabular-nums text-gray-600 dark:text-gray-400">
                sisa saldo {{ Format::durasi($plan->remainingAfterMinutes()) }}
            </span>
        </div>
    @elseif (! $validation->failed())
        <p class="text-gray-500 dark:text-gray-400">Tidak ada saldo yang perlu dialokasikan.</p>
    @endif

    {{-- BR-19 — kuota selalu terlihat, supaya user tahu batasnya sebelum mentok. --}}
    <p class="text-gray-600 dark:text-gray-400">
        Kuota bulan {{ $monthLabel }}:
        <span class="tabular-nums font-medium text-gray-950 dark:text-white">
            {{ $fmtQuota($quotaUsed) }} dari {{ $fmtQuota($quotaLimit) }} hari
        </span>
        terpakai
    </p>
</div>
