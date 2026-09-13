<?php

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;

// Seluruh aplikasi hidup di dalam panel Filament. Tanpa redirect ini
// pengunjung mendarat di halaman welcome bawaan Laravel dan harus mengetik
// /app sendiri. Path diambil dari panel default supaya tetap benar kalau
// suatu saat path panelnya diganti.
Route::get('/', fn () => redirect(Filament::getDefaultPanel()->getPath()));
