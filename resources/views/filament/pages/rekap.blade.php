<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Pilih cakupan rekap</x-slot>
        <x-slot name="description">
            Berkas berisi tiga sheet: Lembur, Klaim, dan Ringkasan. Sheet Lembur memuat
            durasi mentah maupun efektif beserta penanda pembulatan.
        </x-slot>

        <form wire:submit="export" class="space-y-6">
            {{ $this->form }}

            <div class="flex items-center gap-3">
                <x-filament::button type="submit" icon="heroicon-m-arrow-down-tray">
                    Unduh Excel
                </x-filament::button>

                <x-filament::button
                    type="button"
                    color="gray"
                    icon="heroicon-m-document-text"
                    wire:click="exportSpl"
                >
                    Unduh SPL
                </x-filament::button>

                <span class="text-sm text-gray-500 dark:text-gray-400">
                    Format .xlsx
                </span>
            </div>
        </form>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Surat Perintah Lembur (SPL)</x-slot>
        <x-slot name="description">
            Template SPL kantor terisi otomatis dari lembur pada cakupan di atas (kecuali yang ditolak),
            lengkap dengan tanda tangan. Tinggal diunggah tiap tanggal 19.
        </x-slot>

        @php($missing = $this->missingSplFields())

        @if (count($missing))
            <p class="text-sm text-warning-600 dark:text-warning-400">
                Belum diisi: {{ implode(', ', $missing) }}. Lengkapi di
                <a href="{{ filament()->getProfileUrl() }}" class="underline">Profil</a>
                supaya SPL tidak perlu diedit manual.
            </p>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">Data SPL di profil sudah lengkap.</p>
        @endif
    </x-filament::section>

    <p class="text-sm text-gray-500 dark:text-gray-400">
        Angka rupiah dalam rekap ini estimasi berdasarkan catatanmu, bukan perhitungan payroll resmi.
    </p>
</x-filament-panels::page>
