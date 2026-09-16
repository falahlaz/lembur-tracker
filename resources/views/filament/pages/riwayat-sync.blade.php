<x-filament-panels::page>
    {{ $this->table }}

    {{-- F-13 — detail per entri. Ditampilkan sebagai daftar di bawah tabel, bukan
         modal, supaya alasan "dilewati" bisa dibaca berdampingan dengan run-nya. --}}
    @foreach ($this->getTable()->getRecords() as $run)
        @if ($run->items->isNotEmpty())
            <section class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
                <h3 class="text-sm font-semibold mb-3">
                    {{ $run->summary() }}
                    <span class="font-normal text-gray-500">
                        · {{ \App\Support\Format::tanggalRingkas($run->created_at) }}
                        {{ $run->created_at->timezone(config('app.display_timezone'))->format('H:i') }}
                    </span>
                </h3>

                @if ($run->error_message)
                    <p class="text-sm text-danger-600 dark:text-danger-400 mb-3">{{ $run->error_message }}</p>
                @endif

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-gray-500 text-left">
                            <tr>
                                <th class="py-1 pe-4 font-medium">Timesheet</th>
                                <th class="py-1 pe-4 font-medium">Tanggal</th>
                                <th class="py-1 pe-4 font-medium">Durasi</th>
                                <th class="py-1 pe-4 font-medium">Tindakan</th>
                                <th class="py-1 pe-4 font-medium">Lembur</th>
                                <th class="py-1 font-medium">Alasan</th>
                            </tr>
                        </thead>
                        <tbody>
                            {{-- SY-23 — Kimai membatasi satu timesheet maksimal 2 jam, jadi
                                 beberapa baris di sini sering menunjuk SATU lembur yang sama.
                                 Tanpa keterangan itu, "4 timesheet, 1 lembur" terbaca seperti
                                 data hilang. --}}
                            @php($anggota = $run->items->whereNotNull('overtime_record_id')->countBy('overtime_record_id'))
                            @foreach ($run->items as $item)
                                <tr class="border-t border-gray-100 dark:border-white/5">
                                    <td class="py-1.5 pe-4 tabular-nums">#{{ $item->kimai_timesheet_id }}</td>
                                    <td class="py-1.5 pe-4">
                                        {{ $item->overtime_date ? \App\Support\Format::tanggalRingkas($item->overtime_date) : '—' }}
                                    </td>
                                    <td class="py-1.5 pe-4 tabular-nums">
                                        {{ $item->duration_minutes ? \App\Support\Format::durasiRingkas($item->duration_minutes) : '—' }}
                                    </td>
                                    <td class="py-1.5 pe-4">
                                        <x-filament::badge :color="$item->action->getColor()" class="inline-flex">
                                            {{ $item->action->getLabel() }}
                                        </x-filament::badge>
                                    </td>
                                    <td class="py-1.5 pe-4">
                                        @if ($item->overtimeRecord)
                                            {{-- Setiap baris menautkan ke lembur hasilnya. --}}
                                            <a class="text-primary-600 dark:text-primary-400 hover:underline"
                                               href="{{ \App\Filament\Resources\OvertimeRecords\OvertimeRecordResource::getUrl('edit', ['record' => $item->overtimeRecord]) }}">
                                                Lihat lembur
                                            </a>
                                            @if (($anggota[$item->overtime_record_id] ?? 1) > 1)
                                                <span class="text-gray-500">
                                                    · digabung dari {{ $anggota[$item->overtime_record_id] }} timesheet
                                                </span>
                                            @endif
                                        @else
                                            <span class="text-gray-500">—</span>
                                        @endif
                                    </td>
                                    <td class="py-1.5 text-gray-500">{{ $item->reason ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    @endforeach
</x-filament-panels::page>
