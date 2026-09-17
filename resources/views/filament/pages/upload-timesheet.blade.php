@php
    use App\Enums\UploadEntryStatus;
    use App\Enums\UploadStatus;
    use App\Support\Format;

    $upload = $this->upload();
    $entries = $this->entries();
    $berjalan = $this->sedangBerjalan();
@endphp

<x-filament-panels::page>
    {{-- Polling menempel pada elemen biasa, bukan pada atribut tag komponen:
         Blade mem-parse atribut komponen sendiri dan @if di sana tidak dikompilasi.
         Atributnya hilang begitu upload selesai, jadi polling berhenti sendiri. --}}
    <div @if ($berjalan) wire:poll.3s="refreshUpload" @endif>
    <x-filament::section>
        <x-slot name="heading">Pilih berkas</x-slot>
        <x-slot name="description">
            Dua langkah: berkas dibaca dan diperiksa dulu, baru dikirim ke Kimai setelah
            kamu melihat isinya. Entri masuk sebagai pemilik API key kamu sendiri.
        </x-slot>

        <form wire:submit="analyse" class="space-y-6">
            {{ $this->form }}

            <x-filament::button type="submit" icon="heroicon-m-magnifying-glass" wire:target="analyse">
                Periksa berkas
            </x-filament::button>
        </form>
    </x-filament::section>

    @if ($upload)
        @php
            $ringkasan = $this->ringkasanPerSheet();
            $pending = $entries->where('status', UploadEntryStatus::Pending);
            $bentrokBisaDitimpa = $entries->where('status', UploadEntryStatus::Skipped)->where('overridable', true);
        @endphp

        <x-filament::section>
            <x-slot name="heading">Pratinjau · {{ $upload->original_filename }}</x-slot>
            <x-slot name="description">
                {{ $pending->count() }} dari {{ $entries->count() }} entri akan dikirim ke project
                <span class="font-medium">{{ $upload->project_id }}</span>.
            </x-slot>

            {{-- Angka per sheet adalah pemeriksaan kewarasan yang paling cepat:
                 Daily satu periode penuh mestinya sekitar 8 jam x hari kerja. --}}
            <div class="mb-5 flex flex-wrap gap-3">
                @foreach ($ringkasan as $sheet => $angka)
                    <div class="rounded-lg border border-gray-200 px-4 py-2 text-sm dark:border-white/10">
                        <div class="font-medium">{{ $sheet }}</div>
                        <div class="tabular-nums text-gray-600 dark:text-gray-400">
                            {{ $angka['entri'] }} entri · {{ Format::durasiRingkas($angka['menit']) }}
                            @if ($angka['dilewati'] > 0)
                                · <span class="text-amber-600 dark:text-amber-400">{{ $angka['dilewati'] }} dilewati</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            @if (! $upload->duplicates_checked)
                <div class="mb-5 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-200">
                    <strong>Duplikat tidak bisa diperiksa.</strong>
                    Kimai tidak terjangkau saat berkas ini dibaca, jadi entri yang sudah ada di sana
                    tidak ketahuan. Mengirim sekarang berisiko menghasilkan entri ganda yang harus
                    dihapus satu per satu.
                </div>
            @endif

            @if ($upload->issues)
                <div class="mb-5 rounded-lg border border-gray-200 p-4 text-sm dark:border-white/10">
                    <div class="mb-2 font-medium">Yang perlu dilihat</div>
                    <ul class="list-inside list-disc space-y-1 text-gray-600 dark:text-gray-400">
                        @foreach ($upload->issues as $issue)
                            <li>{{ $issue }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($entries->isEmpty())
                <x-lembur.empty-state
                    icon="heroicon-o-document-magnifying-glass"
                    heading="Tidak ada entri"
                    description="Tidak ada sel berisi 'Activity ID' di berkas ini."
                />
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10">
                                <th class="py-2 pr-3">Tanggal</th>
                                <th class="py-2 pr-3">Jam</th>
                                <th class="py-2 pr-3">Sheet</th>
                                <th class="py-2 pr-3 text-right">Activity</th>
                                <th class="py-2 pr-3">Pekerjaan</th>
                                <th class="py-2">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($entries as $entry)
                                <tr @class([
                                    'bg-amber-50/60 dark:bg-amber-400/5' => $entry->status === UploadEntryStatus::Skipped,
                                    'bg-rose-50/60 dark:bg-rose-400/5' => $entry->status === UploadEntryStatus::Failed,
                                ])>
                                    <td class="py-2 pr-3 whitespace-nowrap">{{ Format::tanggalRingkas($entry->work_date) }}</td>
                                    <td class="py-2 pr-3 tabular-nums whitespace-nowrap">{{ $entry->slotRange() }}</td>
                                    <td class="py-2 pr-3">
                                        {{ $entry->sheet }}
                                        @if ($entry->tag)
                                            <span class="ml-1 rounded bg-indigo-100 px-1.5 py-0.5 text-xs text-indigo-700 dark:bg-indigo-400/10 dark:text-indigo-300">{{ $entry->tag }}</span>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-3 text-right tabular-nums">{{ $entry->activity_id }}</td>
                                    <td class="py-2 pr-3">{{ Str::limit(Str::before($entry->description, "\n"), 48) }}</td>
                                    <td class="py-2 text-xs">
                                        <span @class([
                                            'text-gray-500' => $entry->status === UploadEntryStatus::Pending,
                                            'text-emerald-700 dark:text-emerald-400' => $entry->status === UploadEntryStatus::Posted,
                                            'text-amber-700 dark:text-amber-400' => $entry->status === UploadEntryStatus::Skipped,
                                            'text-rose-700 dark:text-rose-400' => $entry->status === UploadEntryStatus::Failed,
                                        ])>
                                            {{ $entry->status->getLabel() }}
                                        </span>
                                        @if ($entry->skip_reason)
                                            <span class="block text-gray-500">{{ $entry->skip_reason }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-6 flex flex-wrap items-center gap-3">
                    @if ($berjalan)
                        <x-filament::button disabled icon="heroicon-m-arrow-path">Mengirim…</x-filament::button>
                    @elseif ($pending->isNotEmpty())
                        <x-filament::button wire:click="commit" icon="heroicon-m-paper-airplane">
                            {{ $upload->status->isResumable() ? 'Lanjutkan' : 'Kirim' }} {{ $pending->count() }} entri ke Kimai
                        </x-filament::button>
                    @endif

                    @if (! $berjalan && $bentrokBisaDitimpa->isNotEmpty() && $upload->status === UploadStatus::Draft)
                        <x-filament::button type="button" color="gray" wire:click="sertakanBentrok">
                            Tetap kirim {{ $bentrokBisaDitimpa->count() }} entri yang bentrok sebagian
                        </x-filament::button>
                    @endif

                    @if (! $berjalan && $upload->status === UploadStatus::Draft)
                        <x-filament::button type="button" color="gray" wire:click="batalkan">Batalkan</x-filament::button>
                    @endif
                </div>

                @if ($upload->status->isFinished())
                    <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">{{ $upload->summary() }}</p>
                @endif
            @endif
        </x-filament::section>
    @endif

    @if ($this->riwayat()->isNotEmpty())
        <x-filament::section collapsible collapsed>
            <x-slot name="heading">Riwayat upload</x-slot>

            <div class="divide-y divide-gray-100 text-sm dark:divide-white/5">
                @foreach ($this->riwayat() as $lama)
                    <div class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <div>
                            <span class="font-medium">{{ $lama->original_filename }}</span>
                            <span class="ml-2 text-gray-500">
                                {{ Format::tanggalRingkas($lama->created_at) }}
                                {{ $lama->created_at->timezone(config('app.display_timezone'))->format('H:i') }}
                            </span>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-gray-600 dark:text-gray-400">{{ $lama->summary() }}</span>
                            <x-filament::badge :color="$lama->status->getColor()">{{ $lama->status->getLabel() }}</x-filament::badge>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif
    </div>
</x-filament-panels::page>
