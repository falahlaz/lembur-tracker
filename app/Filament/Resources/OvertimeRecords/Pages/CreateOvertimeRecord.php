<?php

namespace App\Filament\Resources\OvertimeRecords\Pages;

use App\Filament\Resources\OvertimeRecords\OvertimeRecordResource;
use App\Models\OvertimeRecord;
use App\Support\Format;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateOvertimeRecord extends CreateRecord
{
    protected static string $resource = OvertimeRecordResource::class;

    public function getTitle(): string
    {
        return 'Catat Lembur';
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Karyawan biasa selalu mencatat untuk dirinya sendiri; hanya admin yang
        // melihat pemilih user (OQ-2), dan jejaknya disimpan di created_by_id.
        $data['user_id'] ??= Auth::id();
        $data['created_by_id'] = Auth::id();

        return $data;
    }

    /**
     * Design Brief §6 — notifikasi menyebut HASILNYA, bukan sekadar "berhasil".
     * Imbalan mengisi form harus terasa langsung, itu satu-satunya alasan orang
     * kembali mengisi (R-4).
     */
    protected function getCreatedNotification(): ?Notification
    {
        /** @var OvertimeRecord $record */
        $record = $this->getRecord()->refresh();

        return Notification::make()
            ->success()
            ->title('Lembur '.Format::tanggalRingkas($record->overtime_date).' tersimpan')
            ->body(self::outcomeSentence($record));
    }

    public static function outcomeSentence(OvertimeRecord $record): string
    {
        if ($record->meal_allowance_amount <= 0 && $record->leave_credit_minutes <= 0) {
            return 'Durasinya belum mencapai 4 jam, jadi belum ada uang makan atau cuti pengganti. Catatannya tetap tersimpan.';
        }

        return sprintf(
            'Kamu dapat %s + %s cuti pengganti (berlaku sampai %s).',
            Format::rupiah($record->meal_allowance_amount),
            Format::durasi($record->leave_credit_minutes),
            $record->leaveBalance
                ? Format::tanggalPanjang($record->leaveBalance->expires_at)
                : '—',
        );
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
