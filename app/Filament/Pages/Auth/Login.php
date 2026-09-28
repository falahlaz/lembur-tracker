<?php

namespace App\Filament\Pages\Auth;

use App\Support\Registration;
use Filament\Auth\Pages\Login as BaseLogin;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Route registrasi selalu terdaftar (konfigurasi panel dibaca saat boot,
 * sebelum database pasti siap), jadi tautan "Daftar" disembunyikan di sini
 * selama admin belum membuka registrasi.
 */
class Login extends BaseLogin
{
    public function getSubheading(): string|Htmlable|null
    {
        if (blank($this->userUndertakingMultiFactorAuthentication) && ! Registration::isOpen()) {
            return null;
        }

        return parent::getSubheading();
    }
}
