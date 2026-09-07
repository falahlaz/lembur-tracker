@php use App\Support\Format; @endphp
<x-mail::message>
# Cut-off periode {{ $period->label }} sudah dekat

Periode payroll berjalan
({{ Format::tanggalPanjang($period->period_start) }} – {{ Format::tanggalPanjang($period->period_end) }})
akan segera ditutup.

@if ($unapprovedCount > 0)
Ada **{{ $unapprovedCount }} lembur** yang belum berstatus *disetujui* di periode ini.
Lembur yang pencatatannya melewati batas cut-off tidak dapat diproses di periode berikutnya.
@else
Semua lemburmu di periode ini sudah disetujui. Tidak ada yang perlu dikejar.
@endif

<x-mail::button :url="url('/app/overtime-records')">
Cek daftar lembur
</x-mail::button>

Terima kasih,<br>
{{ config('app.name') }}
</x-mail::message>
