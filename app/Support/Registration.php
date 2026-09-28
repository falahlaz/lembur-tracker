<?php

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Registrasi mandiri hanya dibuka admin saat ada orang baru yang mau ikut
 * memakai aplikasi, lalu ditutup lagi. Default tertutup: selama admin belum
 * pernah membukanya, satu-satunya jalan masuk tetap akun buatan admin.
 */
final class Registration
{
    private const KEY = 'registration_open';

    public static function isOpen(): bool
    {
        return (bool) Cache::rememberForever(
            'app_settings.'.self::KEY,
            fn () => AppSetting::query()->where('key', self::KEY)->value('value') ?? false,
        );
    }

    public static function open(): void
    {
        self::set(true);
    }

    public static function close(): void
    {
        self::set(false);
    }

    private static function set(bool $open): void
    {
        AppSetting::query()->updateOrCreate(['key' => self::KEY], ['value' => $open]);

        Cache::forget('app_settings.'.self::KEY);
    }
}
