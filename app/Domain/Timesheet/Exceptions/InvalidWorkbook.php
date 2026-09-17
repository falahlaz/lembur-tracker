<?php

namespace App\Domain\Timesheet\Exceptions;

use RuntimeException;

/** Berkasnya sendiri tidak bisa dibaca — rusak, bukan xlsx, atau tanpa sheet yang dicari. */
class InvalidWorkbook extends RuntimeException
{
    public function userMessage(): string
    {
        return $this->getMessage();
    }
}
