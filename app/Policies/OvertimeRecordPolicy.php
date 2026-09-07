<?php

namespace App\Policies;

use App\Models\OvertimeRecord;
use App\Models\User;

/**
 * F-01 — setiap user hanya melihat datanya sendiri; admin melihat seluruh data.
 * NFR keamanan mensyaratkan otorisasi per-record lewat Policy, bukan sekadar
 * memfilter query di satu tempat: filter yang terlewat di satu halaman langsung
 * membocorkan data orang lain.
 */
class OvertimeRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return true;   // daftar selalu difilter ke miliknya sendiri
    }

    public function view(User $user, OvertimeRecord $record): bool
    {
        return $this->owns($user, $record);
    }

    public function create(User $user): bool
    {
        return $user->is_active;
    }

    public function update(User $user, OvertimeRecord $record): bool
    {
        return $this->owns($user, $record);
    }

    /**
     * F-02 — menghapus hanya boleh bila saldonya belum terpakai. Selain itu
     * gunakan status `ditolak`, supaya jejaknya tetap ada (NFR retensi).
     */
    public function delete(User $user, OvertimeRecord $record): bool
    {
        if (! $this->owns($user, $record)) {
            return false;
        }

        $balance = $record->leaveBalance;

        return $balance === null
            || ($balance->consumed_minutes === 0 && $balance->held_minutes === 0);
    }

    public function restore(User $user, OvertimeRecord $record): bool
    {
        return $this->owns($user, $record);
    }

    public function forceDelete(User $user, OvertimeRecord $record): bool
    {
        return false;   // tidak ada hard delete pada record lembur
    }

    private function owns(User $user, OvertimeRecord $record): bool
    {
        return $user->isAdmin() || $record->user_id === $user->id;
    }
}
