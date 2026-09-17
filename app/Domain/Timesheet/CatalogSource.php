<?php

namespace App\Domain\Timesheet;

/** Dari mana sebuah daftar project/activity benar-benar berasal. */
enum CatalogSource: string
{
    /** Langsung dari Kimai. */
    case Live = 'live';

    /** Cermin lokal hasil sync katalog, karena Kimai tidak terjangkau. */
    case Mirror = 'mirror';

    /** Dua-duanya kosong — tidak ada daftar sama sekali. */
    case None = 'none';
}
