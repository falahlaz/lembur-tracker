@php
    use App\Support\Format;
    $entries = $this->entries();
@endphp

<x-filament::section>
    <x-slot name="heading">Aktivitas terakhir</x-slot>

    @if ($entries->isEmpty())
        <x-lembur.empty-state
            icon="heroicon-o-clock"
            heading="Belum ada aktivitas"
            description="Catat lembur pertamamu untuk mulai menghitung uang makan dan cuti pengganti."
        >
            <x-filament::button
                tag="a"
                :href="\App\Filament\Resources\OvertimeRecords\OvertimeRecordResource::getUrl('create')"
            >Catat Lembur</x-filament::button>
        </x-lembur.empty-state>
    @else
        <ul class="divide-y divide-gray-200 dark:divide-white/10">
            @foreach ($entries as $entry)
                @php $model = $entry['model']; @endphp
                <li class="flex items-start gap-3 py-2.5 first:pt-0 last:pb-0">
                    <x-filament::icon
                        :icon="$entry['type'] === 'lembur' ? 'heroicon-m-clock' : 'heroicon-m-calendar-days'"
                        class="mt-0.5 h-4 w-4 shrink-0 text-gray-400"
                    />

                    <div class="min-w-0 flex-1">
                        @if ($entry['type'] === 'lembur')
                            <p class="text-sm text-gray-950 dark:text-white">
                                Lembur {{ Format::tanggalPanjang($model->overtime_date) }}
                                <span class="text-gray-500">· {{ Format::durasiRingkas($model->duration_effective_minutes) }}</span>
                            </p>
                            <p class="text-xs text-gray-500">
                                @if ($model->meal_allowance_amount > 0)
                                    {{ Format::rupiah($model->meal_allowance_amount) }}
                                    + {{ Format::durasi($model->leave_credit_minutes) }} cuti pengganti
                                @else
                                    belum mencapai tier
                                @endif
                            </p>
                        @else
                            <p class="text-sm text-gray-950 dark:text-white">
                                Klaim {{ Format::tanggalPanjang($model->claim_date) }}
                                <span class="text-gray-500">· {{ mb_strtolower($model->claim_type->getLabel()) }}</span>
                            </p>
                            <p class="text-xs text-gray-500">
                                {{ Format::durasi($model->minutes_required) }} terpakai
                            </p>
                        @endif
                    </div>

                    <x-filament::badge :color="$model->status->getColor()" size="sm">
                        {{ $model->status->getLabel() }}
                    </x-filament::badge>
                </li>
            @endforeach
        </ul>
    @endif
</x-filament::section>
