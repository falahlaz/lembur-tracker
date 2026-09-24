<?php

namespace App\Domain\Timesheet\Exceptions;

use RuntimeException;

/**
 * Isian form Isi Timesheet yang tidak bisa diubah menjadi entri Kimai.
 *
 * Berbeda dari workbook, bentrok di dalam isian TIDAK dilewati diam-diam: yang
 * mengetiknya sedang ada di depan layar, jadi jauh lebih murah menyuruhnya
 * membetulkan satu baris daripada menebak mana yang dimaksud.
 */
class InvalidManualInput extends RuntimeException
{
    /** @param array<int, string> $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(implode(' ', $problems));
    }

    public function userMessage(): string
    {
        $shown = array_slice($this->problems, 0, 5);
        $rest = count($this->problems) - count($shown);

        return implode("\n", $shown).($rest > 0 ? "\n…dan {$rest} masalah lain." : '');
    }
}
