@php
    use App\Enums\UploadEntryStatus;
    use App\Enums\UploadStatus;
    use App\Support\Format;

    $upload = $this->uploadSaatIni();
    $entries = $this->entries();
    $berjalan = $this->sedangBerjalan();
    $hasil = $this->hasilSelesai();
@endphp

<x-filament-panels::page>
    {{-- Polling menempel pada elemen biasa, bukan pada atribut tag komponen:
         Blade mem-parse atribut komponen sendiri dan @if di sana tidak dikompilasi.
         Atributnya hilang begitu upload selesai, jadi polling berhenti sendiri.

         `space-y-6` di sini BUKAN hiasan. Filament menaruh ritme vertikalnya pada
         anak LANGSUNG dari <x-filament-panels::page>, dan satu-satunya anak langsung
         halaman ini adalah div polling ini — jadi section di dalamnya tidak kebagian
         jarak apa pun dan card-nya beradu border. Halaman lain tidak kena karena
         section-nya memang anak langsung. Jangan dicabut tanpa mengganti jaraknya. --}}
    <div class="space-y-6" @if ($berjalan) wire:poll.3s="refreshUpload" @endif>
    <x-filament::section>
        <x-slot name="heading">Pilih berkas</x-slot>
        <x-slot name="description">
            Dua langkah: berkas dibaca dan diperiksa dulu, baru dikirim ke Kimai setelah
            kamu melihat isinya. Entri masuk sebagai pemilik API key kamu sendiri.
        </x-slot>

        {{-- Dua kemungkinan yang berbeda jauh akibatnya: daftarnya diselamatkan
             cermin lokal (Select tetap jalan, cuma bisa agak tertinggal), atau
             memang tidak ada daftar sama sekali (kembali mengetik ID manual). --}}
        @if ($this->katalogDariLokal())
            {{-- Kalimatnya dirakit di komponen, bukan di sini. Dua jebakan Blade
                 sekaligus: direktif yang menempel pada karakter non-spasi (seperti
                 "</strong>" lalu direktif) TIDAK dikompilasi, dan bentuk sebaris
                 @php(...) di ATAS blok @php/@endphp berikutnya akan berpasangan
                 dengan @endphp itu — menelan seluruh markup di antaranya. --}}
            <div class="mb-5 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-200">
                <strong>Kimai sedang tidak terjangkau.</strong>
                {{ $this->katalogError }}
                Daftar project dan nama activity di bawah dibaca dari <strong>data lokal</strong>{{ $this->keteranganSinkronCermin() }}.
                Activity yang dibuat di Kimai setelah itu belum ada di sini — minta admin menekan
                "Sync Katalog Kimai" di
                <a class="underline" href="{{ \App\Filament\Pages\LegendaKimai::getUrl() }}">halaman Legenda</a>.
                Pengiriman ke Kimai tetap baru bisa jalan setelah Kimai terjangkau lagi.
            </div>
        @elseif ($this->katalogError)
            <div class="mb-5 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-200">
                <strong>Daftar project tidak bisa diambil dari Kimai.</strong>
                {{ $this->katalogError }} Project diisi dengan ID manual, dan nama activity di
                sheet tidak bisa diterjemahkan sampai Kimai terjangkau lagi.
            </div>
        @endif

        <form wire:submit="analyse" class="space-y-6">
            {{ $this->form }}

            <div class="flex flex-wrap items-center gap-3">
                <x-filament::button type="submit" icon="heroicon-m-magnifying-glass" wire:target="analyse">
                    Periksa berkas
                </x-filament::button>

                <x-filament::button type="button" color="gray" icon="heroicon-m-arrow-down-tray"
                    wire:click="downloadTemplate">Unduh template</x-filament::button>

                {{-- Di sinilah orang berdiri ketika lupa nama activity-nya. --}}
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
