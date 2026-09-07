@php
    use App\Support\Format;
    $tabs = $this->getTabs();
@endphp

<x-filament-panels::page>
    {{-- Tab --}}
    <div class="flex gap-1 border-b border-gray-200 dark:border-white/10" role="tablist">
        @foreach ($tabs as $key => $label)
            <button
                type="button"
                role="tab"
                aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                wire:click="setTab('{{ $key }}')"
                @class([
                    'relative -mb-px border-b-2 px-4 py-2.5 text-sm font-medium transition',
                    'border-primary-600 text-primary-600 dark:border-primary-400 dark:text-primary-400' => $tab === $key,
                    'border-transparent text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200' => $tab !== $key,
                ])
            >{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'aktif')
        @php $balances = $this->activeBalances(); @endphp

        @if ($balances->isEmpty())
            <x-lembur.empty-state
                icon="heroicon-o-banknotes"
                heading="Kamu belum punya saldo cuti pengganti"
                description="Saldo muncul otomatis dari lembur minimal 4 jam."
            />
        @else
            <p class="text-sm text-gray-600 dark:text-gray-400">
                Total saldo aktif
                <span class="font-semibold tabular-nums text-gray-950 dark:text-white">
                    {{ Format::durasi($this->totalActiveMinutes()) }}
                </span>
                <span class="text-gray-500">({{ Format::saldoManusiawi($this->totalActiveMinutes()) }})</span>
            </p>

            {{-- Kartu, bukan baris tabel — setiap batch punya tanggal hangus sendiri
                 yang perlu ruang untuk terbaca (Design Brief §4.3). --}}
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($balances as $balance)
                    @php
                        $sisa = $balance->remainingMinutes();
                        $hari = $balance->daysUntilExpiry();
                        $urgensi = $hari <= 7 ? 'danger' : ($hari <= 14 ? 'warning' : 'success');
                        $terpakai = $balance->earned_minutes > 0
                            ? (int) round((($balance->earned_minutes - $sisa) / $balance->earned_minutes) * 100)
                            : 0;
                    @endphp

                    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/5">
                        <p class="text-base font-semibold tabular-nums text-gray-950 dark:text-white">
                            {{ Format::durasi($sisa) }}
                            <span class="font-normal text-gray-500">· {{ Format::saldoManusiawi($sisa) }}</span>
                        </p>

                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                            Dari lembur {{ Format::tanggalPanjang($balance->earned_date) }}
                        </p>

                        <p class="mt-1 flex items-center gap-1.5 text-sm">
                            <span class="text-gray-600 dark:text-gray-400">
                                Berlaku sampai {{ Format::tanggalPanjang($balance->expires_at) }}
                            </span>
                        </p>

                        {{-- Dot berwarna + teks, bukan warna saja — tetap terbaca
                             bagi yang buta warna (Design Brief §4.1). --}}
                        <p @class([
                            'mt-2 flex items-center gap-1.5 text-sm font-medium',
                            'text-rose-600 dark:text-rose-400' => $urgensi === 'danger',
                            'text-amber-600 dark:text-amber-400' => $urgensi === 'warning',
                            'text-emerald-700 dark:text-emerald-400' => $urgensi === 'success',
                        ])>
                            <span @class([
                                'h-2 w-2 rounded-full',
                                'bg-rose-500' => $urgensi === 'danger',
                                'bg-amber-500' => $urgensi === 'warning',
                                'bg-emerald-500' => $urgensi === 'success',
                            ])></span>
                            {{ Format::sisaWaktu($hari) }}
                        </p>

                        @if ($terpakai > 0)
                            <div class="mt-3">
                                <div class="h-1.5 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                                    <div class="h-full rounded-full bg-primary-500" style="width: {{ $terpakai }}%"></div>
                                </div>
                                <p class="mt-1 text-xs text-gray-500">{{ $terpakai }}% sudah dipakai klaim</p>
                            </div>
                        @endif

                        <div class="mt-3">
                            <x-filament::button
                                tag="a"
                                size="sm"
                                :href="\App\Filament\Resources\LeaveClaims\LeaveClaimResource::getUrl('create')"
                            >Pakai saldo ini</x-filament::button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

    @elseif ($tab === 'riwayat')
        {{ $this->table }}

    @else
        @php $expired = $this->expiredBalances(); @endphp

        @if ($summary = $this->wastedSummary())
            <div class="flex items-start gap-2 rounded-xl bg-rose-50 px-4 py-3 text-rose-800 dark:bg-rose-400/10 dark:text-rose-300">
                <x-filament::icon icon="heroicon-m-exclamation-circle" class="mt-0.5 h-5 w-5 shrink-0" />
                <p class="font-medium">{{ $summary }}</p>
            </div>
        @endif

        @if ($expired->isEmpty())
            <x-lembur.empty-state
                icon="heroicon-o-check-circle"
                heading="Belum ada saldo yang hangus"
                description="Selama saldo diklaim sebelum masa berlakunya habis, tab ini akan tetap kosong."
            />
        @else
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($expired as $balance)
                    <div class="rounded-xl border border-gray-200 bg-white p-4 opacity-90 dark:border-white/10 dark:bg-white/5">
                        <div class="flex items-start justify-between gap-2">
                            <p class="text-base font-semibold tabular-nums text-gray-950 dark:text-white">
                                {{ Format::durasi($balance->remainingMinutes()) }}
                            </p>
                            <x-filament::badge :color="$balance->status->getColor()">
                                {{ $balance->status->getLabel() }}
                            </x-filament::badge>
                        </div>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                            Dari lembur {{ Format::tanggalPanjang($balance->earned_date) }}
                        </p>
                        <p class="mt-1 text-sm text-gray-500">
                            Hangus {{ Format::tanggalPanjang($balance->expires_at) }}
                        </p>
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</x-filament-panels::page>
