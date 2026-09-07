@php use App\Support\Format; @endphp
<x-mail::message>
# Saldo cuti pengganti kamu akan hangus

Halo {{ $balance->user->name }},

Kamu punya **{{ Format::durasi($balance->remainingMinutes()) }}** cuti pengganti
({{ Format::saldoManusiawi($balance->remainingMinutes()) }}) yang akan hangus
**{{ Format::sisaWaktu($daysLeft) }}**.

- Dari lembur: {{ Format::tanggalPanjang($balance->earned_date) }}
- Berlaku sampai: **{{ Format::tanggalPanjang($balance->expires_at) }}**

Masa berlaku tidak diperpanjang meskipun jatuh pada weekend atau hari libur.
Kalau tidak diklaim sampai tanggal itu, hakmu hilang.

<x-mail::button :url="url('/app/leave-claims/create')">
Ajukan klaim sekarang
</x-mail::button>

Terima kasih,<br>
{{ config('app.name') }}
</x-mail::message>
