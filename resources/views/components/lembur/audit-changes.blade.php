@props(['changes'])

{{-- Daftar "field: lama → baru". Dua kolom di layar lebar, menumpuk di ponsel;
     nilai lama yang kosong tidak dicetak sama sekali agar tidak ada "— →". --}}
<dl class="grid gap-x-4 text-sm sm:grid-cols-[minmax(0,9rem)_minmax(0,1fr)] sm:gap-y-1">
    @foreach ($changes as $change)
        <dt class="text-gray-500 dark:text-gray-400">{{ $change['label'] }}</dt>

        <dd class="mb-2 min-w-0 text-gray-950 sm:mb-0 dark:text-white">
            @if ($change['has_from'])
                @if ($change['from_url'])
                    <a
                        href="{{ $change['from_url'] }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="break-all text-gray-500 hover:underline dark:text-gray-400"
                    >{{ $change['from'] }}</a>
                @else
                    <span class="whitespace-pre-line break-words text-gray-500 dark:text-gray-400">{{ $change['from'] }}</span>
                @endif

                <span aria-hidden="true" class="text-gray-400 dark:text-gray-500">→</span>
            @endif

            @if ($change['to_url'])
                <a
                    href="{{ $change['to_url'] }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="break-all text-primary-600 hover:underline dark:text-primary-400"
                >{{ $change['to'] }}</a>
            @else
                <span class="whitespace-pre-line break-words">{{ $change['to'] }}</span>
            @endif
        </dd>
    @endforeach
</dl>
