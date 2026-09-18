<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // HTTPS ditutup di reverse proxy VPS; php-fpm di belakangnya selalu
        // melihat request http polos. Tanpa baris ini Laravel mengabaikan
        // X-Forwarded-Proto, Request::isSecure() jadi false, dan asset()
        // menulis http:// di halaman https — browser memblokir modul Filament
        // (select.js, date-time-picker.js, file-upload.js) sebagai mixed
        // content. Akibatnya date picker, select, dan file upload mati tanpa
        // satu pun error di console.
        //
        // `at: '*'` di Laravel bukan berarti "percaya semua IP": ia hanya
        // mempercayai IP pemanggil (REMOTE_ADDR), yakni container nginx. Rantai
        // X-Forwarded-For tetap dibaca dari kanan, jadi $request->ip() justru
        // mengembalikan IP klien sebenarnya — rate limit login di
        // AppServiceProvider baru benar-benar per-klien karenanya.
        //
        // env() sengaja tidak dipakai di sini: closure ini dijalankan saat
        // HttpKernel di-resolve di public/index.php, sebelum .env dimuat.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
