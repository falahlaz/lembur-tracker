@php use App\Support\Format; @endphp

<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Pilih berkas</x-slot>
        <x-slot name="description">
            Impor dijalankan dua langkah: berkas dibaca dan dihitung dulu, baru disimpan
            setelah kamu melihat hasilnya.
        </x-slot>

        <form wire:submit="analyse" class="space-y-6">
            {{ $this->form }}

            <div class="flex flex-wrap items-center gap-3">
                <x-filament::button type="submit" icon="heroicon-m-magnifying-glass">
                    Periksa berkas
                </x-filament::button>

                <x-filament::button
                    type="button"
                    color="gray"
                    icon="heroicon-m-arrow-down-tray"
                    wire:click="downloadTemplate"
                >Unduh template CSV</x-filament::button>
            </div>
        </form>
    </x-filament::section>

    @if ($preview !== null)
        @php
            $valid = $preview->filter(fn ($r) => $r->isValid());
            $invalid = $preview->reject(fn ($r) => $r->isValid());
            $expiredCount = $valid->filter(fn ($r) => $r->alreadyExpired)->count();
        @endphp

        <x-filament::section>
            <x-slot name="heading">Pratinjau</x-slot>
            <x-slot name="description">
                {{ $valid->count() }} baris siap disimpan, {{ $invalid->count() }} dilewati.
                @if ($expiredCount > 0)
                    {{ $expiredCount }} di antaranya menghasilkan saldo yang masa berlakunya
                    sudah lewat, jadi akan langsung berstatus hangus.
                @endif
            </x-slot>

            @if ($preview->isEmpty())
                <x-lembur.empty-state
                    icon="heroicon-o-document-magnifying-glass"
                    heading="Berkasnya kosong"
                    description="Tidak ada baris data yang bisa dibaca selain header."
                />
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10">
                                <th class="py-2 pr-3">Baris</th>
                                <th class="py-2 pr-3">Tanggal</th>
                                <th class="py-2 pr-3">Jam</th>
                                <th class="py-2 pr-3 text-right">Durasi</th>
                                <th class="py-2 pr-3">Tier</th>
                                <th class="py-2 pr-3 text-right">Uang makan</th>
                                <th class="py-2 pr-3">Saldo</th>
                                <th class="py-2">Keterangan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($preview as $row)
                                <tr @class(['bg-rose-50/60 dark:bg-rose-400/5' => ! $row->isValid()])>
                                    <td class="py-2 pr-3 tabular-nums text-gray-500">{{ $row->lineNumber }}</td>
                                    <td class="py-2 pr-3">{{ $row->date ?? '—' }}</td>
                                    <td class="py-2 pr-3 tabular-nums">
                                        {{ $row->startTime ?? '—' }}–{{ $row->endTime ?? '—' }}
                                    </td>
                                    <td class="py-2 pr-3 text-right tabular-nums">
                                        {{ $row->preview ? Format::durasiRingkas($row->preview->effectiveMinutes) : '—' }}
                                    </td>
                                    <td class="py-2 pr-3">
                                        {{ $row->preview ? $row->preview->tier->getLabel() : '—' }}
                                    </td>
                                    <td class="py-2 pr-3 text-right tabular-nums">
                                        {{ $row->preview ? Format::rupiah($row->preview->mealAmount) : '—' }}
                                    </td>
                                    <td class="py-2 pr-3">
                                        @if ($row->preview && $row->preview->qualifies())
                                            {{ Format::durasiRingkas($row->preview->leaveMinutes) }}
                                            @if ($row->alreadyExpired)
                                                <span class="ml-1 text-xs text-rose-600 dark:text-rose-400">langsung hangus</span>
                                            @endif
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="py-2 text-xs">
                                        @if ($row->isValid())
                                            <span class="text-emerald-700 dark:text-emerald-400">siap</span>
                                        @else
                                            <span class="text-rose-700 dark:text-rose-400">
                                                {{ implode(' · ', $row->errors) }}
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($valid->isNotEmpty())
                    <div class="mt-6">
                        <x-filament::button type="button" wire:click="simpan" icon="heroicon-m-check">
                            Simpan {{ $valid->count() }} lembur
                        </x-filament::button>
                    </div>
                @endif
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
