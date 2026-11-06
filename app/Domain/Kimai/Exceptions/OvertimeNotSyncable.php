<?php

namespace App\Domain\Kimai\Exceptions;

/** Perubahan lembur yang tidak bisa diteruskan ke Kimai dengan aman; tidak ada yang diubah. */
class OvertimeNotSyncable extends KimaiException
{
    public function userMessage(): string
    {
        return $this->getMessage();
    }
}
