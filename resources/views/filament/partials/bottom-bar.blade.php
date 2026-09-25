@php
    use App\Filament\Pages\CutiPengganti;
    use App\Filament\Resources\OvertimeRecords\OvertimeRecordResource;

    $dashboard = \Filament\Facades\Filament::getUrl();
    $catat = OvertimeRecordResource::getUrl('create');
    $cuti = CutiPengganti::getUrl();
    $path = request()->path();
    // Selama password sementara belum diganti, semua tautan ini hanya memantul
    // balik ke halaman ganti password.
    $locked = \Filament\Facades\Filament::auth()->user()?->mustChangePassword() ?? false;
@endphp

@unless ($locked)

{{-- Design Brief §7 — bottom bar hanya di layar kecil, dengan "Catat Lembur"
     di tengah dan menonjol: itulah alur terpenting di seluruh aplikasi, dan
     tempat paling mungkin dipakai adalah kasur jam 11 malam. --}}
<nav
    class="fixed inset-x-0 bottom-0 z-30 flex items-stretch border-t border-gray-200 bg-white/95 backdrop-blur md:hidden dark:border-white/10 dark:bg-gray-900/95"
    style="padding-bottom: env(safe-area-inset-bottom);"
    aria-label="Navigasi utama"
>
    <a href="{{ $dashboard }}"
       @class([
           'flex min-h-[56px] flex-1 flex-col items-center justify-center gap-0.5 text-xs',
           'text-primary-600 dark:text-primary-400' => $path === 'app',
           'text-gray-500 dark:text-gray-400' => $path !== 'app',
       ])>
        <x-filament::icon icon="heroicon-o-home" class="h-5 w-5" />
        Dashboard
    </a>

    {{-- Target sentuh menonjol, tetap >= 44x44px. --}}
    <a href="{{ $catat }}"
       class="flex min-h-[56px] flex-1 flex-col items-center justify-center gap-0.5 text-xs font-medium text-white"
       aria-label="Catat lembur">
        <span class="-mt-5 flex h-12 w-12 items-center justify-center rounded-full bg-primary-600 shadow-lg">
            <x-filament::icon icon="heroicon-m-plus" class="h-6 w-6 text-white" />
        </span>
        <span class="text-gray-700 dark:text-gray-300">Catat</span>
    </a>

    <a href="{{ $cuti }}"
       @class([
           'flex min-h-[56px] flex-1 flex-col items-center justify-center gap-0.5 text-xs',
           'text-primary-600 dark:text-primary-400' => str_contains($path, 'cuti-pengganti'),
           'text-gray-500 dark:text-gray-400' => ! str_contains($path, 'cuti-pengganti'),
       ])>
        <x-filament::icon icon="heroicon-o-calendar-days" class="h-5 w-5" />
        Cuti
    </a>
</nav>

{{-- Ruang supaya konten terakhir tidak tertutup bar. --}}
<div class="h-20 md:hidden" aria-hidden="true"></div>
@endunless
