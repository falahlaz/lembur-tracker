<?php

namespace Tests\Feature\Filament;

use Livewire\Component;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Penjaga satu bug yang mahal dan sama sekali tidak bersuara.
 *
 * Livewire memesan sederet nama pendek di objek `$wire`, dan daftar itu diperiksa
 * LEBIH DULU daripada method komponen. Method bernama sama karena itu tidak pernah
 * terjangkau dari browser: `wire:click="commit"` memanggil `$commit` bawaan Livewire
 * — sinkronisasi state biasa dengan `calls: []` — server membalas 200 dengan render
 * yang identik, dan layar sama sekali tidak berubah. Tanpa error, tanpa peringatan,
 * tanpa apa pun di log.
 *
 * Yang membuatnya lolos sekian lama: `Livewire::test()->call('commit')` tetap hijau,
 * karena memanggil PHP-nya langsung tanpa melewati `$wire`. Jadi tes per-komponen
 * TIDAK bisa menangkap ini — hanya pemeriksaan nama seperti di bawah yang bisa.
 */
class LivewireNamingTest extends TestCase
{
    /**
     * Isi `aliases` di vendor/livewire/livewire/dist/livewire.esm.js.
     *
     * @var array<int, string>
     */
    private const DIPESAN_LIVEWIRE = [
        'on', 'el', 'id', 'js', 'get', 'set', 'call', 'hook', 'commit', 'watch',
        'entangle', 'dispatch', 'dispatchTo', 'dispatchSelf', 'upload',
        'uploadMultiple', 'removeUpload', 'cancelUpload',
    ];

    #[Test]
    public function tidak_ada_method_komponen_yang_memakai_nama_cadangan_livewire(): void
    {
        $tabrakan = [];

        foreach ($this->komponenLivewire() as $class) {
            $reflection = new ReflectionClass($class);

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                // Hanya yang ditulis di repo ini; method warisan Filament/Livewire
                // bukan urusan kita dan tidak bisa kita ganti namanya.
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                if (in_array($method->getName(), self::DIPESAN_LIVEWIRE, true)) {
                    $tabrakan[] = $class.'::'.$method->getName().'()';
                }
            }
        }

        $this->assertSame([], $tabrakan, implode("\n", [
            'Nama berikut dipesan Livewire di objek $wire, jadi tidak akan pernah',
            'terpanggil dari wire:click/wire:submit — tombolnya diam tanpa error:',
            ...$tabrakan,
            'Ganti namanya (mis. commit() -> kirim()).',
        ]));
    }

    /**
     * Semua komponen Livewire milik aplikasi ini.
     *
     * @return array<int, class-string>
     */
    private function komponenLivewire(): array
    {
        $out = [];

        foreach (['Filament/Pages', 'Filament/Widgets', 'Filament/Resources', 'Livewire'] as $dir) {
            $path = app_path($dir);

            if (! is_dir($path)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $class = 'App\\'.str_replace(
                    ['/', '.php'],
                    ['\\', ''],
                    substr($file->getPathname(), strlen(app_path()) + 1)
                );

                if (! class_exists($class)) {
                    continue;
                }

                $reflection = new ReflectionClass($class);

                if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Component::class)) {
                    continue;
                }

                $out[] = $class;
            }
        }

        // Kalau pemindainya sendiri yang rusak, tesnya hijau tanpa memeriksa apa
        // pun — itu lebih berbahaya daripada tidak ada tes sama sekali.
        $this->assertNotEmpty($out, 'Tidak satu pun komponen Livewire ditemukan; pemindainya yang salah.');

        return $out;
    }
}
