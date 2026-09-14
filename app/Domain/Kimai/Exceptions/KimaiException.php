<?php

namespace App\Domain\Kimai\Exceptions;

use RuntimeException;

/**
 * §9 — seluruh kegagalan Kimai dibungkus ulang menjadi exception milik sendiri.
 *
 * Ini bukan kerapian belaka: Illuminate\Http\Client\RequestException membawa
 * objek request LENGKAP dengan header Authorization, dan handler exception
 * Laravel menuliskannya ke log. Itu jalur kebocoran token yang paling sering
 * terlewat (SR-2), jadi RequestException tidak boleh pernah lolos dari lapisan ini.
 */
abstract class KimaiException extends RuntimeException
{
    /** Pesan siap tampil untuk user — tanpa detail teknis, tanpa token. */
    abstract public function userMessage(): string;
}
