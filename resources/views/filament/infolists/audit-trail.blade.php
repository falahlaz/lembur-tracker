@php
    // F-10 — timeline riwayat perubahan. Data sudah diterjemahkan sepenuhnya oleh
    // App\Support\AuditTrail; di sini murni tata letak.
    $entries = $getState() ?? [];
@endphp

@if (empty($entries))
    <x-lembur.empty-state
        icon="heroicon-o-clock"
        heading="Belum ada perubahan tercatat"
        description="Setiap suntingan pada record ini akan muncul di sini beserta nilai lama dan barunya."
    />
@else
    <ol>
        @foreach ($entries as $entry)
            @php
                // Kelas warna ditulis utuh, bukan dirangkai dari variabel: Tailwind
                // memindai berkas ini sebagai teks dan hanya mengenali yang literal.
                $dot = match ($entry['color']) {
                    'success' => 'bg-success-500',
                    'primary' => 'bg-primary-500',
                    'danger' => 'bg-danger-500',
                    default => 'bg-gray-400 dark:bg-gray-500',
                };
            @endphp

            <li class="group relative flex gap-3 pb-5 last:pb-0">
                {{-- Garis penghubung; berhenti di entri terakhir. --}}
                <span
                    aria-hidden="true"
                    class="absolute bottom-0 start-[7px] top-5 w-px bg-gray-200 group-last:hidden dark:bg-white/10"
                ></span>

                <span
                    aria-hidden="true"
                    class="relative mt-1 size-3.5 shrink-0 rounded-full ring-4 ring-white dark:ring-gray-900 {{ $dot }}"
                ></span>

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-baseline gap-x-2">
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ $entry['event_label'] }} oleh <span class="font-medium text-gray-950 dark:text-white">{{ $entry['who'] }}</span>
                        </p>

                        <time class="text-xs tabular-nums text-gray-500 dark:text-gray-400" title="{{ $entry['when_title'] }}">
                            {{ $entry['when'] }}
                        </time>
                    </div>

                    @if ($entry['event'] === 'created')
                        {{-- Pada pembuatan record seluruh nilai lama kosong, jadi daftar
                             lengkapnya disembunyikan di balik disclosure. --}}
                        @if ($entry['summary'])
                            <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ $entry['summary'] }}</p>
                        @endif

                        @if (filled($entry['changes']))
                            <details class="group/detail mt-1.5">
                                <summary class="inline-block cursor-pointer text-xs font-medium text-primary-600 hover:underline dark:text-primary-400">
                                    <span class="group-open/detail:hidden">Lihat detail</span>
                                    <span class="hidden group-open/detail:inline">Sembunyikan detail</span>
                                </summary>

                                <div class="mt-2">
                                    <x-lembur.audit-changes :changes="$entry['changes']" />
                                </div>
                            </details>
                        @endif
                    @elseif (filled($entry['changes']))
                        <div class="mt-2">
                            <x-lembur.audit-changes :changes="$entry['changes']" />
                        </div>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>
@endif
