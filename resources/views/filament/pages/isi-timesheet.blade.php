@php
    $upload = $this->uploadSaatIni();
    $entries = $this->entries();
    $berjalan = $this->sedangBerjalan();
    $hasil = $this->hasilSelesai();
    $ringkasanIsian = $this->ringkasanIsian();
@endphp

<x-filament-panels::page>
    {{-- Sama seperti upload-timesheet: div polling ini satu-satunya anak langsung
         halaman, jadi `space-y-6` di sini yang memberi jarak antar section. --}}
    <div class="space-y-6" @if ($berjalan) wire:poll.3s="refreshUpload" @endif>
    <x-filament::section>
        <x-slot name="heading">Tulis pekerjaanmu</x-slot>
        <x-slot name="description">
            Tulis blok waktunya apa adanya, misalnya 09:00–18:00. Kimai hanya menerima entri
            maksimal 2 jam, jadi setiap baris dipecah otomatis menjadi potongan 2 jam dari jam
            mulainya. Istirahat tidak dipotong otomatis; kalau mau dikosongkan, tulis sebagai
            dua baris. Pakai "Salin ke rentang tanggal" untuk mengisi banyak hari sekaligus.
        </x-slot>

        @if ($this->katalogDariLokal())
            <div class="mb-5 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-200">
                <strong>Kimai sedang tidak terjangkau.</strong>
                {{ $this->katalogError }}
                Daftar project dan activity di bawah dibaca dari <strong>data lokal</strong>{{ $this->keteranganSinkronCermin() }}.
                Pengiriman ke Kimai tetap baru bisa jalan setelah Kimai terjangkau lagi.
            </div>
        @elseif ($this->katalogError)
            <div class="mb-5 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-200">
                <strong>Daftar project tidak bisa diambil dari Kimai.</strong>
                {{ $this->katalogError }}
            </div>
        @endif

        <form wire:submit="periksa" class="space-y-6">
            {{ $this->form }}

            {{-- Hitungan lokal dari isian, sebelum satu permintaan pun ke Kimai:
                 cara tercepat melihat hari yang kurang atau kelebihan jam. --}}
            @if ($ringkasanIsian !== [])
                <div class="flex flex-wrap gap-3">
                    @foreach ($ringkasanIsian as $hari)
                        <div class="rounded-lg border border-gray-200 px-4 py-2 text-sm dark:border-white/10">
                            <div class="font-medium">{{ $hari['label'] }}</div>
                            <div class="tabular-nums text-gray-600 dark:text-gray-400">
                                {{ $this->durasiTeks($hari['menit']) }} · {{ $hari['entri'] }} entri Kimai
                            </div>
                            @if ($hari['menit_daily'] > 0 && $hari['menit_daily'] !== 480)
                                <div class="text-xs text-amber-600 dark:text-amber-400">
                                    Jam kerja biasa {{ $this->durasiTeks($hari['menit_daily']) }}, bukan 8j
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="flex flex-wrap items-center gap-3">
                <x-filament::button type="submit" icon="heroicon-m-magnifying-glass" wire:target="periksa">
                    Periksa isian
                </x-filament::button>

                <x-filament::button tag="a" color="gray" icon="heroicon-m-book-open"
                    href="{{ \App\Filament\Pages\LegendaKimai::getUrl() }}">Legenda activity</x-filament::button>

                @if ($this->katalogTersedia())
                    <x-filament::button type="button" color="gray" icon="heroicon-m-arrow-path"
                        wire:click="muatUlangKatalog">Muat ulang daftar project</x-filament::button>
                @endif
            </div>
        </form>
    </x-filament::section>

    @include('filament.pages.partials.timesheet-preview')

    </div>
</x-filament-panels::page>
