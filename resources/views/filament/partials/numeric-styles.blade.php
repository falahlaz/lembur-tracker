{{-- Design Brief §5.2 / §10 — seluruh kolom angka dan stat tile memakai
     tabular-nums. Tanpa ini digit tidak sejajar antar baris, dan kolom rupiah
     jadi sulit dibandingkan sekilas. Sel yang rata kanan di Filament selalu
     berisi angka, jadi itulah pengaitnya. --}}
<style>
    .fi-wi-stats-overview-stat-value,
    .fi-ta-cell[class*="text-end"] .fi-ta-text-item-label,
    .fi-ta-summary-cell {
        font-variant-numeric: tabular-nums;
    }
</style>
