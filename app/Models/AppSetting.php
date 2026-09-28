<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Satu baris = satu pengaturan aplikasi yang bisa diubah admin dari panel. */
#[Fillable(['key', 'value'])]
class AppSetting extends Model
{
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
