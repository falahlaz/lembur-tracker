<?php

namespace App\Support;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use ReflectionEnum;
use Spatie\Activitylog\Models\Activity;

/**
 * F-10 / SOP §10 — audit trail yang bisa dibaca manusia.
 *
 * Activitylog menyimpan apa adanya isi kolom database: nama field snake_case
 * dan nilai mentah (`recorded`, `120`, `22:00:00`). Yang ditulis di sini adalah
 * lapisan terjemahannya: nama field jadi label Indonesia yang sama dengan
 * halaman detail, nilainya lewat App\Support\Format dan label enum. Tidak ada
 * format durasi/rupiah/tanggal yang ditulis ulang di Blade (lihat Format).
 *
 * Tipe nilai dibaca dari $casts model, bukan dari daftar per-field, supaya
 * model apa pun yang memakai LogsActivity ikut terlayani tanpa perubahan.
 */
final class AuditTrail
{
    /**
     * Label field. Mengikuti label yang sama persis dengan form dan infolist;
     * kalau di layar atas tertulis "Durasi efektif", riwayat tidak boleh
     * menyebutnya dengan nama lain.
     *
     * @var array<string, string>
     */
    private const FIELD_LABELS = [
        // OvertimeRecord
        'overtime_date' => 'Tanggal',
        'start_time' => 'Jam mulai',
        'end_time' => 'Jam selesai',
        'status' => 'Status',
        'duration_raw_minutes' => 'Durasi mentah',
        'duration_effective_minutes' => 'Durasi efektif',
        'tier' => 'Tier',
        'meal_allowance_amount' => 'Uang makan',
        'leave_credit_minutes' => 'Cuti pengganti',
        'work_description' => 'Deskripsi',
        'evidence_url' => 'Evidence',
        'notes' => 'Catatan',
        'break_minutes' => 'Istirahat',
        'evidence_needs_review' => 'Evidence perlu ditinjau',
        'locally_modified' => 'Diubah manual',

        // LeaveClaim
        'claim_date' => 'Tanggal klaim',
        'claim_type' => 'Jenis klaim',
        'arrival_time' => 'Jam datang',
        'minutes_required' => 'Saldo terpakai',
        'needs_review' => 'Perlu ditinjau',
    ];

    /** @var array<string, array{label: string, color: string, icon: string}> */
    private const EVENTS = [
        'created' => ['label' => 'Dicatat', 'color' => 'success', 'icon' => 'heroicon-m-plus-circle'],
        'updated' => ['label' => 'Diubah', 'color' => 'primary', 'icon' => 'heroicon-m-pencil-square'],
        'deleted' => ['label' => 'Dihapus', 'color' => 'danger', 'icon' => 'heroicon-m-trash'],
        'restored' => ['label' => 'Dipulihkan', 'color' => 'gray', 'icon' => 'heroicon-m-arrow-uturn-left'],
    ];

    private const EMPTY = '—';

    /**
     * @return array<int, array{
     *     event: string, event_label: string, color: string, icon: string,
     *     when: string, when_title: string, who: string,
     *     summary: ?string, what: string,
     *     changes: array<int, array{label: string, from: string, to: string, from_url: ?string, to_url: ?string, has_from: bool}>
     * }>
     */
    public static function for(Model $record): array
    {
        $casts = $record->getCasts();

        return Activity::query()
            ->where('subject_type', $record->getMorphClass())
            ->where('subject_id', $record->getKey())
            // Dua perubahan dalam detik yang sama akan berurutan sembarang bila
            // hanya diurut waktu; id memberi urutan yang pasti.
            ->latest()
            ->orderByDesc('id')
            ->get()
            ->map(fn (Activity $activity) => self::entry($activity, $casts))
            ->all();
    }

    /**
     * @param  array<string, string>  $casts
     * @return array<string, mixed>
     */
    private static function entry(Activity $activity, array $casts): array
    {
        // activitylog v5 memindahkan nilai lama/baru ke kolom khusus
        // `attribute_changes`; `properties` disisakan untuk data custom.
        $changesBag = $activity->attribute_changes ?? $activity->properties ?? [];
        $old = $changesBag['old'] ?? [];
        $new = $changesBag['attributes'] ?? [];

        $event = (string) $activity->event;
        $meta = self::EVENTS[$event] ?? ['label' => Str::headline($event), 'color' => 'gray', 'icon' => 'heroicon-m-ellipsis-horizontal'];

        $changes = collect($new)
            ->map(fn ($value, $field) => self::change($field, $old[$field] ?? null, $value, $casts))
            ->values()
            ->all();

        $at = $activity->created_at->timezone(config('app.display_timezone'));

        return [
            'event' => $event,
            'event_label' => $meta['label'],
            'color' => $meta['color'],
            'icon' => $meta['icon'],
            'when' => $at->translatedFormat('j M Y, H:i'),
            'when_title' => Format::tanggalPanjang($at).' pukul '.$at->format('H:i'),
            'who' => $activity->causer?->name ?? 'Sistem',
            'summary' => $event === 'created' ? self::summary($new, $casts) : null,
            'changes' => $changes,
            // String datar untuk pemakai non-visual (test, ekspor, fallback).
            'what' => $changes === []
                ? $meta['label']
                : collect($changes)
                    ->map(fn (array $c) => $c['has_from']
                        ? "{$c['label']}: {$c['from']} → {$c['to']}"
                        : "{$c['label']}: {$c['to']}")
                    ->implode(' · '),
        ];
    }

    /**
     * @param  array<string, string>  $casts
     * @return array{label: string, from: string, to: string, from_url: ?string, to_url: ?string, has_from: bool}
     */
    private static function change(string $field, mixed $from, mixed $to, array $casts): array
    {
        $fromText = self::value($field, $from, $casts);
        $toText = self::value($field, $to, $casts);
        $fromUrl = self::url($from);
        $toUrl = self::url($to);

        return [
            'label' => self::label($field),
            'from' => $fromUrl === null ? $fromText : self::shortUrl($fromUrl),
            'to' => $toUrl === null ? $toText : self::shortUrl($toUrl),
            'from_url' => $fromUrl,
            'to_url' => $toUrl,
            // Nilai lama yang kosong tidak perlu dicetak sebagai "— →"; pada
            // event `created` itu berlaku untuk seluruh baris.
            'has_from' => $fromText !== self::EMPTY,
        ];
    }

    private static function label(string $field): string
    {
        // Field baru tidak boleh tampil sebagai kolom kosong; headline() setidaknya
        // memecah snake_case jadi kata sampai labelnya ditambahkan di sini.
        return self::FIELD_LABELS[$field] ?? Str::headline($field);
    }

    /**
     * Ringkasan event `created`. Pada pembuatan record, kolom "nilai lama"
     * seluruhnya kosong, sehingga daftar 15 baris "— → nilai" hanya jadi tembok
     * teks. Yang benar-benar dicari orang cuma tiga: kapan, jam berapa, berapa lama.
     *
     * @param  array<string, mixed>  $new
     * @param  array<string, string>  $casts
     */
    private static function summary(array $new, array $casts): ?string
    {
        $parts = [];

        foreach (['overtime_date', 'claim_date'] as $field) {
            if (isset($new[$field])) {
                $parts[] = self::value($field, $new[$field], $casts);
                break;
            }
        }

        if (isset($new['start_time'], $new['end_time'])) {
            $parts[] = Format::jam((string) $new['start_time']).'–'.Format::jam((string) $new['end_time']);
        } elseif (isset($new['arrival_time'])) {
            $parts[] = 'datang '.Format::jam((string) $new['arrival_time']);
        }

        foreach (['duration_effective_minutes', 'minutes_required'] as $field) {
            if (isset($new[$field])) {
                $parts[] = self::value($field, $new[$field], $casts);
                break;
            }
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * Nilai mentah database → teks yang dibaca orang.
     *
     * @param  array<string, string>  $casts
     */
    private static function value(string $field, mixed $value, array $casts): string
    {
        if ($value === null || $value === '') {
            return self::EMPTY;
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: self::EMPTY;
        }

        $cast = $casts[$field] ?? null;

        if ($label = self::enumLabel($cast, $value)) {
            return $label;
        }

        if (is_bool($value) || $cast === 'boolean' || $cast === 'bool') {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'ya' : 'tidak';
        }

        // `datetime` diperiksa lebih dulu: ia juga diawali "date", sehingga urutan
        // terbalik akan membuang jam dari setiap kolom waktu.
        if ($cast !== null && Str::startsWith($cast, ['datetime', 'immutable_datetime'])) {
            $at = Carbon::parse((string) $value)->timezone(config('app.display_timezone'));

            return Format::tanggalPanjang($at).' pukul '.$at->format('H:i');
        }

        if ($cast !== null && Str::startsWith($cast, ['date', 'immutable_date'])) {
            return Format::tanggalPanjang(Carbon::parse((string) $value));
        }

        // Tipe menit dan rupiah sama-sama integer di database, jadi hanya nama
        // kolomnya yang bisa membedakan "120 menit" dari "Rp120".
        return match (true) {
            str_ends_with($field, '_minutes') => Format::durasi((int) $value),
            str_ends_with($field, '_amount') => Format::rupiah((int) $value),
            str_ends_with($field, '_time') && preg_match('/^\d{1,2}:\d{2}/', (string) $value) === 1 => Format::jam((string) $value),
            default => (string) $value,
        };
    }

    /** Label enum dari cast model, sehingga `status` tahu enum mana yang dipakainya. */
    private static function enumLabel(?string $cast, mixed $value): ?string
    {
        if ($cast === null || ! enum_exists($cast) || ! is_a($cast, \BackedEnum::class, true)) {
            return null;
        }

        // Enum int-backed menolak string "0", dan sebaliknya; tipe backing-nya
        // harus dibaca dulu supaya tryFrom() tidak melempar TypeError.
        $backing = (string) (new ReflectionEnum($cast))->getBackingType();
        $case = $backing === 'int'
            ? (is_numeric($value) ? $cast::tryFrom((int) $value) : null)
            : $cast::tryFrom((string) $value);

        // tryFrom(), bukan from(): nilai enum lama yang sudah dihapus dari kode
        // tidak boleh membuat halaman detail gagal dimuat.
        return match (true) {
            $case instanceof HasLabel => (string) $case->getLabel(),
            $case !== null => $case->name,
            default => null,
        };
    }

    /** URL apa adanya untuk dijadikan tautan; selain itu null. */
    private static function url(mixed $value): ?string
    {
        return is_string($value) && Str::startsWith($value, ['http://', 'https://'])
            ? $value
            : null;
    }

    /**
     * "timesheet.codeoffice.net/…/182772/edit" — evidence Kimai panjangnya bisa
     * melebihi lebar layar ponsel; yang informatif hanya host dan ekor path.
     */
    public static function shortUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        $segments = array_values(array_filter(explode('/', (string) parse_url($url, PHP_URL_PATH))));

        if ($segments === []) {
            return $host;
        }

        $tail = implode('/', array_slice($segments, -2));

        return count($segments) > 2 ? "{$host}/…/{$tail}" : "{$host}/{$tail}";
    }
}
