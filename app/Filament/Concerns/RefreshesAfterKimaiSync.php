<?php

namespace App\Filament\Concerns;

use Livewire\Attributes\On;

/**
 * F-12 — widget dashboard adalah komponen Livewire TERPISAH dari halamannya.
 * wire:poll di tombol sync hanya me-render ulang halaman; Livewire 3 tidak ikut
 * me-render anak saat induknya berubah. Jadi begitu sync selesai, halaman segar
 * tetapi isi widget tertinggal basi.
 *
 * Yang basi itu TableWidget dan Widget biasa: keduanya tidak punya polling
 * bawaan (default Table::poll() null). StatsOverviewWidget sudah punya
 * wire:poll.5s dari Filament, jadi baginya event ini bukan soal basi melainkan
 * soal jeda — angkanya berubah seketika, bukan menunggu sampai lima detik.
 *
 * Badannya sengaja kosong. Cukup dengan Livewire memproses panggilan ini,
 * komponennya dihidrasi ulang dari nol: cache tabel dan record Filament hidup di
 * properti protected yang tidak ikut snapshot, sehingga query-nya otomatis jalan
 * lagi. resetTable() hanya perlu kalau datanya berubah di dalam SATU request
 * yang sama — bukan kasus di sini.
 */
trait RefreshesAfterKimaiSync
{
    #[On('kimai-sync-selesai')]
    public function refreshAfterKimaiSync(): void
    {
        //
    }
}
