<?php

namespace App\Http\Middleware;

use App\Filament\Pages\Auth\GantiPasswordAwal;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Selama password masih yang diberikan admin, seluruh panel tertutup kecuali
 * halaman ganti password dan logout.
 */
class EnsurePasswordIsChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();

        if (! $user?->mustChangePassword()) {
            return $next($request);
        }

        $panel = Filament::getCurrentOrDefaultPanel();

        if ($request->routeIs(
            GantiPasswordAwal::getRouteName(),
            $panel->generateRouteName('auth.logout'),
        )) {
            return $next($request);
        }

        return redirect()->to(GantiPasswordAwal::getUrl());
    }
}
