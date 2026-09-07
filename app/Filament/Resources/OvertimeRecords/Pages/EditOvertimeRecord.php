<?php

namespace App\Filament\Resources\OvertimeRecords\Pages;

use App\Filament\Resources\OvertimeRecords\OvertimeRecordResource;
use App\Models\OvertimeRecord;
use App\Support\Format;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditOvertimeRecord extends EditRecord
{
    protected static string $resource = OvertimeRecordResource::class;

    public function getTitle(): string
    {
        return 'Ubah Lembur';
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                // F-02 — menghapus hanya boleh bila saldonya belum terpakai.
                ->modalDescription(fn (OvertimeRecord $record) => $record->leaveBalance
                    && ($record->leaveBalance->held_minutes > 0 || $record->leaveBalance->consumed_minutes > 0)
                        ? 'Saldo dari lembur ini sudah dipakai klaim. Gunakan status "Ditolak" supaya jejaknya tetap ada.'
                        : 'Catatan ini akan dihapus beserta saldo cuti pengganti yang dihasilkannya.'),
        ];
    }

    /**
     * F-02 — mengubah lembur yang saldonya sudah dipakai klaim memicu konfirmasi
     * eksplisit, karena konsekuensinya jatuh ke BR-23: batch dibatalkan dan klaim
     * yang terdampak harus ditinjau ulang oleh user.
     */
    protected function getSaveFormAction(): \Filament\Actions\Action
    {
        return parent::getSaveFormAction()
            ->requiresConfirmation(fn () => $this->balanceIsInUse())
            ->modalHeading('Saldo dari lembur ini sudah dipakai')
            ->modalDescription('Kalau perubahan ini mengurangi hak yang diperoleh, saldonya dibatalkan dan klaim yang memakainya akan ditandai perlu ditinjau. Lanjutkan?')
            ->modalSubmitActionLabel('Ya, simpan perubahan');
    }

    private function balanceIsInUse(): bool
    {
        /** @var OvertimeRecord $record */
        $record = $this->getRecord();
        $balance = $record->leaveBalance;

        return $balance !== null
            && ($balance->held_minutes > 0 || $balance->consumed_minutes > 0);
    }

    protected function getSavedNotification(): ?Notification
    {
        /** @var OvertimeRecord $record */
        $record = $this->getRecord()->refresh();

        return Notification::make()
            ->success()
            ->title('Perubahan tersimpan')
            ->body(CreateOvertimeRecord::outcomeSentence($record));
    }
}
