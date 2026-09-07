@php use App\Support\Format; @endphp
<x-mail::message>
# Rekap periode {{ $period->label }}

Periode {{ Format::tanggalPanjang($period->period_start) }} – {{ Format::tanggalPanjang($period->period_end) }}
baru saja ditutup. Ini rekapmu:

<x-mail::table>
| Rincian                  | Jumlah                                             |
|:-------------------------|---------------------------------------------------:|
| Jumlah lembur            | {{ $estimate->recordCount }} catatan                |
| Total jam                | {{ Format::durasi($estimate->totalMinutes) }}       |
| Estimasi uang makan      | {{ Format::rupiah($estimate->maximum) }}            |
| Sudah disetujui          | {{ Format::rupiah($estimate->certain) }}            |
| Cuti pengganti diperoleh | {{ Format::durasi($leaveMinutesEarned) }}           |
</x-mail::table>

Angka rupiah di atas estimasi berdasarkan catatanmu, bukan perhitungan payroll resmi.

<x-mail::button :url="url('/app')">
Buka dashboard
</x-mail::button>

Terima kasih,<br>
{{ config('app.name') }}
</x-mail::message>
