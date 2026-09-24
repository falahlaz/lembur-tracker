{{-- Pratinjau, panel hasil, dan riwayat sebuah TimesheetUpload — dipakai bersama
     oleh Upload Timesheet dan Isi Timesheet. Membaca $upload, $entries, $berjalan,
     dan $hasil dari view pemanggil; komponennya memakai PreviewsTimesheetUpload. --}}
@php
    use App\Enums\UploadEntryStatus;
    use App\Enums\UploadStatus;
    use App\Models\TimesheetUpload;
    use App\Support\Format;
@endphp

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

            {{-- Satu-satunya tanda "sedang jalan" dulu hanya tombol mati di DASAR
                 tabel, dan jeda sampai 3 detik sampai tick poll berikutnya tidak
                 berkata apa-apa. Panelnya ditaruh di atas tabel supaya terbaca tanpa
                 menggulir, dan ikonnya benar-benar berputar: gerakan itu yang
                 membedakan "sedang bekerja" dari "layar menggantung". CSS murni,
                 tanpa Alpine. --}}
            @if ($berjalan)
                @php $kemajuan = $this->kemajuan(); @endphp

                <div class="mb-5 rounded-lg border border-indigo-200 bg-indigo-50 p-4 text-sm text-indigo-900 dark:border-indigo-400/30 dark:bg-indigo-400/10 dark:text-indigo-200">
                    <p class="flex items-start gap-1.5 font-medium">
                        <x-filament::icon icon="heroicon-m-arrow-path" class="mt-0.5 h-4 w-4 shrink-0 animate-spin" />
                        {{-- Selama masih `queued` belum ada satu baris pun yang
                             bergerak; "Mengirim…" di situ berbohong. --}}
                        <span>
                            {{ $upload->status === UploadStatus::Queued
                                ? 'Menunggu giliran di antrean…'
                                : 'Mengirim ke Kimai…' }}
                        </span>
                    </p>

                    @if ($kemajuan['sasaran'] > 0)
                        {{-- Lebarnya inline, bukan kelas Tailwind: theme panel memakai
                             source(none), jadi kelas yang dirakit dinamis tidak pernah
                             ikut terkompilasi. Sama seperti bar di cuti-pengganti. --}}
                        <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-indigo-200 dark:bg-white/10">
                            <div class="h-full rounded-full bg-primary-500" style="width: {{ $kemajuan['persen'] }}%"></div>
                        </div>
                        <p class="mt-1 text-xs tabular-nums">
                            {{ $kemajuan['selesai'] }} dari {{ $kemajuan['sasaran'] }} entri ·
                            halaman ini memperbarui dirinya sendiri, tidak perlu di-refresh.
                        </p>
                    @endif
                </div>
            @endif

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
                                <th class="py-2 pr-3">Activity</th>
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
                                    <td class="py-2 pr-3">
                                        {{-- Nama kalau ada; sel format lama hanya punya id. --}}
                                        {{ $entry->activity_name ?? '#'.$entry->activity_id }}
                                    </td>
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
                        <x-filament::button type="button" wire:click="kirim" icon="heroicon-m-paper-airplane">
                            {{ $upload->status->isResumable() ? 'Lanjutkan' : 'Kirim' }} {{ $pending->count() }} entri ke Kimai
                        </x-filament::button>
                    @endif

                    @if (! $berjalan && $bentrokBisaDitimpa->isNotEmpty() && $upload->status === UploadStatus::Draft)
                        <x-filament::button type="button" color="gray" wire:click="sertakanBentrok">
                            Tetap kirim {{ $bentrokBisaDitimpa->count() }} entri yang bentrok sebagian
                        </x-filament::button>
                    @endif

                    {{-- Dulu syaratnya `! $berjalan && status === Draft`, dan itu berarti
                         upload yang nyangkut di `queued` tidak punya tombol apa pun:
                         Kirim mati karena $berjalan, Batalkan hilang karena statusnya
                         bukan Draft. Sekarang selalu ada jalan keluar; yang benar-benar
                         sedang berjalan ditolak di batalkan() dengan notifikasi. --}}
                    @if (! in_array($upload->status, [UploadStatus::Success, UploadStatus::Cancelled], true))
                        <x-filament::button type="button" color="gray" wire:click="batalkan">
                            {{ $upload->status === UploadStatus::Draft ? 'Batalkan' : 'Buang pratinjau ini' }}
                        </x-filament::button>
                    @endif
                </div>

                @if ($upload->status->isFinished())
                    <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">{{ $upload->summary() }}</p>
                @endif
            @endif
        </x-filament::section>
    @endif

    {{-- Pratinjaunya sudah dibuang refreshUpload(), dan tanpa pengganti layar jadi
         kosong melompong persis setelah pengiriman berhasil. Kata "Pratinjau" sengaja
         TIDAK dipakai di sini: tes memakainya sebagai penjaga bahwa tabelnya benar-benar
         pergi. --}}
    @if ($hasil)
        <x-filament::section>
            <x-slot name="heading">Upload selesai</x-slot>

            <p class="flex items-start gap-1.5 text-sm text-emerald-700 dark:text-emerald-400">
                <x-filament::icon icon="heroicon-m-check-circle" class="mt-0.5 h-5 w-5 shrink-0" />
                <span>{{ $hasil->summary() }} · {{ $hasil->original_filename }}</span>
            </p>

            {{-- Riwayat upload bukan halaman tersendiri, jadi ini menunjuk ke bawah,
                 bukan ke tautan yang tidak ada. --}}
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                Rinciannya tersimpan di "Riwayat upload" di bawah.
            </p>

            <div class="mt-4">
                <x-filament::button type="button" color="gray" wire:click="tutupHasil">Tutup</x-filament::button>
            </div>
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
                            @if ($lama->source === TimesheetUpload::SOURCE_FORM)
                                <x-filament::badge color="gray" class="ml-1 inline-flex">Isian</x-filament::badge>
                            @endif
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
