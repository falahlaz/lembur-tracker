<?php

namespace App\Providers\Filament;

use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use App\Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('app')
            ->path('app')
            ->login()
            ->brandName('LemburKu')
            ->colors([
                'primary' => Color::Indigo,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'danger' => Color::Rose,
                'gray' => Color::Slate,
            ])
            ->font('Inter')
            // Filament 4 tidak lagi mengirim utility Tailwind, hanya kelas
            // `fi-*`. Tanpa tema ini setiap kelas di view kita mati diam-diam:
            // markup tetap render, tata letaknya saja yang hilang.
            ->viteTheme('resources/css/filament/app/theme.css')
            ->defaultThemeMode(ThemeMode::System)
            ->navigationGroups([
                NavigationGroup::make()->label('Beranda'),
                NavigationGroup::make()->label('Pencatatan'),
                NavigationGroup::make()->label('Lainnya'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            // Registrasi (bukan urutan): Filament hanya mendaftarkan widget ke
            // Livewire lewat panel. Tanpa ini, widget tetap ter-render tapi
            // permintaan lazy-load-nya balik 419 "Halaman Kadaluwarsa".
            // Urutan tampilnya tetap ditentukan Dashboard::getWidgets().
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            // Design Brief §7 — bottom bar mobile; disuntik lewat render hook
            // agar berlaku di seluruh halaman panel tanpa mengubah layout.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => view('filament.partials.numeric-styles')->render(),
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => view('filament.partials.bottom-bar')->render(),
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
