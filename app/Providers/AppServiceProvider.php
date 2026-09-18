<?php

namespace App\Providers;

use App\Domain\Lembur\RuleResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Cache versi aturan per-tanggal berumur satu request — BR-24 dibaca
        // berkali-kali saat menghitung ulang sebuah tanggal.
        $this->app->singleton(RuleResolver::class);
    }

    public function boot(): void
    {
        // Jaring pengaman kalau reverse proxy di depan tidak mengirim
        // X-Forwarded-Proto: tanpa scheme https, asset() menulis http:// di
        // halaman https dan browser memblokir modul Filament sebagai mixed
        // content. Jalur normalnya tetap TrustProxies di bootstrap/app.php —
        // ini hanya dinyalakan lewat FORCE_HTTPS=true kalau proxy tidak bisa
        // diperbaiki.
        if (config('app.force_https')) {
            URL::forceScheme('https');
        }

        // Semua tanggal immutable: kalkulasi saldo dan cut-off berkeliling antar
        // service, dan Carbon yang mutable membuat ->addMonth() diam-diam mengubah
        // objek milik pemanggil.
        Date::use(\Carbon\CarbonImmutable::class);

        // "5 April 2026", bukan "5 April 2026" versi Inggris (Design Brief §5.3).
        Carbon::setLocale(config('app.locale'));

        // NFR keamanan — rate limit login 5x per menit per email+IP.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by(Str::lower((string) $request->input('email')).'|'.$request->ip()));

        // NFR keamanan — "remember me" 30 hari. Sesi biasa 8 jam, diatur
        // lewat SESSION_LIFETIME=480.
        $this->app->booted(function () {
            $guard = Auth::guard();

            if (method_exists($guard, 'setRememberDuration')) {
                $guard->setRememberDuration(60 * 24 * 30);
            }
        });

        Model::shouldBeStrict(! $this->app->isProduction());
        Model::automaticallyEagerLoadRelationships();
    }
}
