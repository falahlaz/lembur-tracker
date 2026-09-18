<?php

namespace App\Filament\Pages;

use App\Domain\Kimai\Exceptions\KimaiException;
use App\Domain\Kimai\Exceptions\KimaiTokenInvalid;
use App\Domain\Kimai\Exceptions\KimaiUnavailable;
use App\Domain\Kimai\KimaiCatalogMirror;
use App\Domain\Kimai\KimaiCatalogSync;
use App\Domain\Kimai\KimaiConnection;
use App\Domain\Timesheet\SlotLabel;
use App\Models\KimaiActivity;
use App\Models\KimaiProject;
use App\Support\Format;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Legenda pengisian workbook timesheet: daftar activity dan project Kimai.
 *
 * Alasannya sederhana dan berulang tiap periode — nama activity di sel workbook
 * harus PERSIS, dan satu-satunya cara memastikannya dulu adalah membuka Kimai di
 * tab lain. Halaman ini memindahkan daftar itu ke tempat orang sedang bekerja.
 *
 * Sengaja TIDAK meng-override canAccess(), berbeda dari UploadTimesheet dan
 * RiwayatSync yang dijaga hasKimaiConnection(): yang paling butuh legenda justru
 * mereka yang tidak punya token sama sekali dan mengisi Excel-nya dengan tangan.
 * Datanya pun tidak berasal dari token siapa pun, melainkan dari cermin lokal
 * yang diisi admin. Satu-satunya pagar adalah canAccessPanel() (is_active).
 */
class LegendaKimai extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|\UnitEnum|null $navigationGroup = 'Pencatatan';

    /** Tepat di bawah Upload Timesheet (3) — di situlah legenda ini dicari. */
    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Legenda Activity';

    protected static ?string $title = 'Legenda Activity Kimai';

    protected string $view = 'filament.pages.legenda-kimai';

    /** Memo per request; dipakai subheading, empty state, DAN blade. */
    private ?bool $kosongMemo = null;

    private ?CarbonImmutable $disinkronkanMemo = null;

    private bool $disinkronkanTerbaca = false;

    // ---------------------------------------------------------------- header

    protected function getHeaderActions(): array
    {
        return [$this->hubungkanKimaiAction(), $this->syncKatalogAction()];
    }

    /**
     * Syarat "admin" menempel pada ACTION-nya, bukan cuma pada getHeaderActions().
     *
     * Filament 4 mendaftarkan sendiri setiap method ber-akhiran Action() sebagai
     * action komponen, jadi menyaringnya di getHeaderActions() saja hanya membuatnya
     * tidak tergambar — ia tetap ada dan tetap bisa dipanggil lewat Livewire.
     * visible() di sini yang benar-benar menutupnya, dan syncKatalog() tetap
     * memeriksa ulang sebelum menyentuh apa pun.
     */
    public function syncKatalogAction(): Action
    {
        // Tidak perlu padanan rebuildKimaiSyncAction() di sini: berbeda dari tombol
        // sync timesheet, label, ikon, dan status disabled tombol ini TIDAK berubah
        // setelah diklik, dan ia tidak membawa wire:poll — jadi objek Action lama
        // yang di-cache Filament tetap ter-render identik. Begitu ada yang menambah
        // state "Menyinkronkan…", penyusunan ulang itu jadi wajib.
        return Action::make('syncKatalog')
            ->label('Sync Katalog Kimai')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(fn (): bool => $this->bolehSync())
            ->requiresConfirmation(false)
            ->action(fn () => $this->syncKatalog());
    }

    /**
     * Pola yang sama dengan SyncsWithKimai: admin yang belum menyimpan token
     * melihat tautan ke pengaturannya, bukan tombol yang sudah pasti gagal.
     */
    public function hubungkanKimaiAction(): Action
    {
        return Action::make('hubungkanKimai')
            ->label('Hubungkan Kimai')
            ->icon(Heroicon::OutlinedLink)
            ->color('gray')
            ->visible(function (): bool {
                $user = Auth::user();

                return $user !== null && $user->isAdmin() && ! $user->hasKimaiConnection();
            })
            ->url(Preferensi::getUrl());
    }

    private function bolehSync(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->isAdmin() && $user->hasKimaiConnection();
    }

    /**
     * Dijalankan LANGSUNG, bukan lewat queue.
     *
     * Sync timesheet memang harus di-queue: ia menyusuri 90 hari entri berhalaman
     * dan menulis transaksi per sesi. Yang ini tiga sampai lima permintaan GET dan
     * dua upsert atas beberapa ratus baris — selesai dalam hitungan detik. Versi
     * queue-nya akan menuntut job, penanda pending, polling, penyusunan ulang
     * action, dan tabel run tersendiri hanya untuk membawa hasilnya kembali ke
     * layar; tidak satu pun terbayar di sini. Sinkron juga yang membuat notifikasi
     * bisa langsung menyebut selisih sebenarnya.
     *
     * Risikonya jelas dan berbatas: Kimai yang lambat, sebesar kimai.timeout (20
     * detik) dikali jumlah permintaan.
     */
    public function syncKatalog(): void
    {
        if (! $this->bolehSync()) {
            return;
        }

        $user = Auth::user();

        // Kunci GLOBAL, bukan per user: cerminnya cuma satu, dan dua admin yang
        // menekan bersamaan akan saling berebut langkah pemangkasannya.
        $lock = Cache::lock('kimai-catalog-sync', (int) config('kimai.catalog_lock_ttl'));

        if (! $lock->get()) {
            Notification::make()->warning()
                ->title('Sync katalog sedang berjalan')
                ->body('Tunggu sebentar, lalu coba lagi.')
                ->send();

            return;
        }

        try {
            $hasil = app(KimaiCatalogSync::class)->run($user);
        } catch (KimaiTokenInvalid $e) {
            // Token yang sama dipakai sync timesheet; ditandai sekali di satu tempat.
            app(KimaiConnection::class)->markInvalid($user);

            Notification::make()->danger()->title('Token Kimai ditolak')
                ->body($e->userMessage())->send();

            return;
        } catch (KimaiUnavailable $e) {
            Notification::make()->warning()->title('Kimai tidak terjangkau')
                ->body($e->userMessage().' Legenda yang lama tidak diubah.')->send();

            return;
        } catch (KimaiException $e) {
            Notification::make()->danger()->title('Kimai menolak permintaan')
                ->body($e->userMessage())->send();

            return;
        } finally {
            $lock->release();
        }

        // Datanya berubah DI DALAM request yang sama dengan yang merender tabelnya,
        // dan Filament menyimpan record tabel per request — tanpa ini, tabel baru
        // terisi setelah halaman dimuat ulang manual.
        $this->lupakanMemo();
        $this->resetTable();

        Notification::make()
            ->status($hasil->berubah() ? 'success' : 'info')
            ->title($hasil->summary())
            ->send();
    }

    private function lupakanMemo(): void
    {
        $this->kosongMemo = null;
        $this->disinkronkanMemo = null;
        $this->disinkronkanTerbaca = false;
    }

    // ------------------------------------------------------------- keterangan

    public function getSubheading(): ?string
    {
        if ($this->cerminKosong()) {
            return 'Katalog belum pernah disinkronkan dari Kimai.';
        }

        $waktu = $this->terakhirDisinkronkan();

        return sprintf(
            'Disinkronkan %s · %d project · %d activity',
            $waktu === null
                ? '—'
                : Format::tanggalRingkas($waktu).' '
                    .$waktu->timezone(config('app.display_timezone'))->format('H:i'),
            KimaiProject::query()->count(),
            KimaiActivity::query()->count(),
        );
    }

    public function cerminKosong(): bool
    {
        return $this->kosongMemo ??= app(KimaiCatalogMirror::class)->kosong();
    }

    public function terakhirDisinkronkan(): ?CarbonImmutable
    {
        if (! $this->disinkronkanTerbaca) {
            $this->disinkronkanMemo = app(KimaiCatalogMirror::class)->terakhirDisinkronkan();
            $this->disinkronkanTerbaca = true;
        }

        return $this->disinkronkanMemo;
    }

    /** Langkah berikutnya berbeda tergantung siapa yang membaca. */
    public function pesanKosong(): string
    {
        if (! $this->cerminKosong()) {
            return 'Coba ubah kata kunci pencarian atau filter project-nya.';
        }

        return Auth::user()?->isAdmin()
            ? 'Tekan tombol "Sync Katalog Kimai" di kanan atas untuk menariknya dari Kimai.'
            : 'Minta admin menekan tombol "Sync Katalog Kimai" di halaman ini.';
    }

    /** @return Collection<int, KimaiProject> */
    public function daftarProject(): Collection
    {
        return KimaiProject::query()->orderBy('name')->get();
    }

    /** @return array<string, array<int, string>> */
    public function contohSlot(): array
    {
        return SlotLabel::CONTOH_SLOT;
    }

    /**
     * Jam yang BENAR-BENAR dihasilkan sebuah label, dihitung parser sungguhan —
     * bukan ditulis tangan di blade. Konvensi jam 12 di template ini terbalik, dan
     * tabel legenda yang salah soal itu lebih berbahaya daripada tidak ada tabel.
     */
    public function bacaSlot(string $label): ?string
    {
        $slot = SlotLabel::parse($label);

        if ($slot === null) {
            return null;
        }

        return sprintf('%02d:00–%02d:00', $slot->startHour, $slot->endHour);
    }

    // ------------------------------------------------------------------ tabel

    public function table(Table $table): Table
    {
        return $table
            // shouldBeStrict() melarang lazy loading; relasi project WAJIB ikut dimuat.
            ->query(fn (): Builder => KimaiActivity::query()->with('project'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nama activity')
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::Medium)
                    ->copyable()
                    // Yang disalin adalah BARIS SIAP TEMPEL, bukan namanya saja:
                    // itulah bentuk yang dibaca parser di sel workbook.
                    ->copyableState(fn (KimaiActivity $record): string => $record->barisSel())
                    ->copyMessage('Disalin — tinggal tempel ke sel workbook')
                    // navigator.clipboard tidak ada di halaman non-HTTPS, dan tombol
                    // salinnya diam-diam tidak melakukan apa pun di sana. Teks yang
                    // sama ditampilkan juga supaya selalu bisa diblok dan disalin manual.
                    ->description(fn (KimaiActivity $record): string => $record->barisSel()),

                TextColumn::make('project.name')
                    ->label('Project')
                    ->searchable()
                    ->sortable()
                    ->placeholder('Semua project (global)')
                    ->description(fn (KimaiActivity $record): ?string => $record->project?->customer),

                TextColumn::make('kimai_id')
                    ->label('ID')
                    ->alignEnd()
                    // Jarang dibutuhkan sejak pencocokan lewat nama, tapi format lama
                    // "Activity ID: 8" masih didukung parser.
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('project_id')
                    ->label('Project')
                    ->options(fn (): array => $this->daftarProject()
                        ->mapWithKeys(fn (KimaiProject $p) => [$p->kimai_id => $p->label()])
                        ->all())
                    // Memilih project IKUT menampilkan activity global: itulah yang
                    // benar-benar boleh ditulis untuk project itu. Menyembunyikannya
                    // membuat legenda ini menyesatkan.
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->forProject((int) $data['value'])
                        : $query),
            ])
            ->recordUrl(null)
            ->paginated([25, 50, 100])
            ->emptyStateIcon(Heroicon::OutlinedBookOpen)
            ->emptyStateHeading(fn (): string => $this->cerminKosong()
                ? 'Katalog belum pernah disinkronkan'
                : 'Tidak ada activity yang cocok')
            ->emptyStateDescription(fn (): string => $this->pesanKosong());
    }
}
