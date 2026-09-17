<x-filament-panels::page>
    {{-- Cermin yang belum pernah diisi harus mustahil terlewat: tanpa ini halaman
         terlihat seperti "memang tidak ada activity", bukan "belum disinkronkan". --}}
    @if ($this->cerminKosong())
        <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-200">
            <strong>Katalog belum pernah disinkronkan dari Kimai.</strong>
            {{ $this->pesanKosong() }}
        </div>
    @endif

    {{ $this->table }}

    <x-filament::section collapsible>
        <x-slot name="heading">Cara menulis sel timesheet</x-slot>
        <x-slot name="description">
            Nama activity dicocokkan dari baris pertama sel, bukan dari kolom terpisah.
        </x-slot>

        <div class="space-y-4 text-sm">
            <pre class="overflow-x-auto rounded-lg bg-gray-50 p-4 font-mono text-xs leading-relaxed dark:bg-white/5"><strong>Activity: 31_DEV_FEATURE</strong>

Sprint 8 - MTA-1867
1. Review AI generated code</pre>

            <ul class="list-disc space-y-1.5 ps-5 text-gray-600 dark:text-gray-400">
                <li>Baris pertama wajib diawali <code>Activity:</code>, lalu nama persis seperti di tabel atas.</li>
                <li>Sisa isi sel — setelah baris itu — menjadi deskripsi entri di Kimai.</li>
                <li>
                    Huruf besar-kecil, spasi, <code>_</code>, dan <code>-</code> diabaikan saat mencocokkan:
                    <code>31_DEV_FEATURE</code> sama dengan <code>31 dev feature</code>.
                </li>
                <li>
                    Nama yang cocok ke <strong>lebih dari satu</strong> activity akan ditolak, bukan ditebak —
                    menebak berarti jam kerja masuk ke activity yang keliru tanpa ada yang tahu.
                </li>
                <li>Format lama <code>Activity ID: 8</code> masih dibaca; kolom ID di tabel atas bisa dimunculkan lewat tombol kolom.</li>
            </ul>
        </div>
    </x-filament::section>

    <x-filament::section collapsible collapsed>
        <x-slot name="heading">Label jam yang dikenali</x-slot>
        <x-slot name="description">
            Slot dibaca dari labelnya di kolom A, bukan dari nomor barisnya — jumlah baris boleh berbeda antar periode.
        </x-slot>

        <div class="space-y-5 text-sm">
            <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-amber-900 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-200">
                <strong>Template ini membalik konvensi jam 12.</strong>
                Di dalamnya <code>12 AM</code> berarti tengah hari dan <code>12 PM</code> berarti tengah malam —
                kebalikan dari arti bakunya. Kolom "dibaca jadi" di bawah dihitung oleh parser yang sama
                dengan yang membaca workbook, jadi itulah jam yang benar-benar dikirim ke Kimai.
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                @foreach ($this->contohSlot() as $sheet => $labels)
                    <div>
                        <h4 class="mb-2 font-semibold">Sheet {{ $sheet }}</h4>
                        <table class="w-full text-sm">
                            <thead class="text-left text-gray-500">
                                <tr>
                                    <th class="py-1 pe-4 font-medium">Label di kolom A</th>
                                    <th class="py-1 font-medium">Dibaca jadi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($labels as $label)
                                    <tr class="border-t border-gray-100 dark:border-white/5">
                                        <td class="py-1.5 pe-4 font-mono text-xs">{{ $label }}</td>
                                        <td class="py-1.5 tabular-nums text-gray-500">{{ $this->bacaSlot($label) ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endforeach
            </div>
        </div>
    </x-filament::section>

    <x-filament::section collapsible collapsed>
        <x-slot name="heading">Project</x-slot>
        <x-slot name="description">
            Baris "Project ID" di workbook diisi angka; daftar ini supaya angkanya tidak perlu ditebak.
        </x-slot>

        @if ($this->daftarProject()->isEmpty())
            <p class="text-sm text-gray-500">{{ $this->pesanKosong() }}</p>
        @else
            <table class="w-full text-sm">
                <thead class="text-left text-gray-500">
                    <tr>
                        <th class="py-1 pe-4 font-medium">Project</th>
                        <th class="py-1 pe-4 font-medium">Customer</th>
                        <th class="py-1 font-medium">Project ID</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->daftarProject() as $project)
                        <tr class="border-t border-gray-100 dark:border-white/5">
                            <td class="py-1.5 pe-4">{{ $project->name }}</td>
                            <td class="py-1.5 pe-4 text-gray-500">{{ $project->customer ?? '—' }}</td>
                            <td class="py-1.5 tabular-nums">{{ $project->kimai_id }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-panels::page>
