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

                <span class="text-sm text-gray-500 dark:text-gray-400">
                    Format .xlsx
                </span>
            </div>
        </form>
    </x-filament::section>

    <p class="text-sm text-gray-500 dark:text-gray-400">
        Angka rupiah dalam rekap ini estimasi berdasarkan catatanmu, bukan perhitungan payroll resmi.
    </p>
</x-filament-panels::page>
