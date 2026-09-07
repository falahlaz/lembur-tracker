<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\Import;
use Maatwebsite\Excel\Concerns\ToArray;

/**
 * Membaca sheet apa adanya — termasuk baris header — karena HistoricalImporter
 * yang memetakan kolomnya sendiri dan perlu tahu nomor baris aslinya untuk
 * melaporkan kesalahan.
 */
class RawSheetImport implements Import, ToArray
{
    public function array(array $array): void
    {
        // Tidak ada yang perlu dilakukan; Excel::toArray mengembalikan datanya.
    }
}
