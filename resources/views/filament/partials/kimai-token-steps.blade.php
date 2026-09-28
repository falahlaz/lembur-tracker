{{-- Cara membuat API token Kimai — dipakai bersama oleh halaman Daftar dan Preferensi. --}}
@php($kimaiUrl = rtrim(config('kimai.base_url'), '/').'/')

<ol class="list-decimal space-y-1 ps-5 text-sm text-gray-600 dark:text-gray-400">
    <li>
        Login ke
        <a href="{{ $kimaiUrl }}" target="_blank" rel="noopener" class="font-medium text-primary-600 underline dark:text-primary-400">{{ $kimaiUrl }}</a>
    </li>
    <li>Klik profil di bagian kanan atas.</li>
    <li>Pilih <span class="font-medium">API Access</span> dari dropdown.</li>
    <li>
        Klik tombol <span class="font-medium">+ Create</span> untuk membuat token API baru.
        Token hanya ditampilkan sekali, jadi salin utuh sebelum menutup halamannya.
    </li>
    <li>Tempel token di kolom di bawah, lalu tes koneksinya.</li>
</ol>
