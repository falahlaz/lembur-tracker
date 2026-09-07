<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** BR-09 — 19 bulan sebelumnya s/d 18 bulan berjalan; label mengikuti bulan period_end. */
#[Fillable(['period_start', 'period_end', 'label'])]
class PayrollPeriod extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date:Y-m-d',
            'period_end' => 'immutable_date:Y-m-d',
        ];
    }

    public function overtimeRecords(): HasMany
    {
        return $this->hasMany(OvertimeRecord::class);
    }
}
