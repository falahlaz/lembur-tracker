@php
    use App\Support\Format;
    $balances = $this->balances();
    $total = $balances->sum(fn ($b) => $b->remainingMinutes());
    $soonest = $balances->first();
@endphp

<div class="flex flex-col gap-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between dark:border-rose-400/20 dark:bg-rose-400/10">
    <div class="flex items-start gap-3">
        <x-filament::icon icon="heroicon-m-exclamation-triangle" class="mt-0.5 h-5 w-5 shrink-0 text-rose-600 dark:text-rose-400" />

        <div>
            <p class="font-semibold text-rose-900 dark:text-rose-200">
                {{ Format::durasi($soonest->remainingMinutes()) }} cuti pengganti kamu
                hangus {{ Format::sisaWaktu($soonest->daysUntilExpiry()) }}
            </p>

            <p class="mt-0.5 text-sm text-rose-800 dark:text-rose-300">
                Dari lembur {{ Format::tanggalPanjang($soonest->earned_date) }}
                · berlaku sampai {{ Format::tanggalPanjang($soonest->expires_at) }}
                @if ($balances->count() > 1)
                    · {{ $balances->count() - 1 }} batch lain juga mendekati masa berlaku
                    (total {{ Format::durasi($total) }})
                @endif
            </p>
        </div>
    </div>

    <x-filament::button
        tag="a"
        color="danger"
        icon="heroicon-m-arrow-right"
        icon-position="after"
        :href="\App\Filament\Resources\LeaveClaims\LeaveClaimResource::getUrl('create')"
        class="shrink-0"
    >Ajukan klaim</x-filament::button>
</div>
