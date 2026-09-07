@php use App\Support\Format; @endphp
<x-mail::message>
# Ada klaim yang perlu kamu tinjau

Lembur tanggal **{{ Format::tanggalPanjang($balance->earned_date) }}** dibatalkan
atau diubah, padahal saldonya sudah dipakai untuk klaim berikut:

- Klaim tanggal **{{ Format::tanggalPanjang($claim->claim_date) }}**
  ({{ mb_strtolower($claim->claim_type->getLabel()) }},
  {{ Format::durasi($claim->minutes_required) }})

Klaim ini **tidak** dibatalkan otomatis — data yang sudah kamu komunikasikan ke
atasan tidak diubah tanpa sepengetahuanmu. Silakan tinjau dan perbaiki sendiri.

<x-mail::button :url="url('/app/leave-claims')">
Tinjau klaim
</x-mail::button>

Terima kasih,<br>
{{ config('app.name') }}
</x-mail::message>
