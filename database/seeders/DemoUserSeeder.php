<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@lemburku.test'],
            [
                'name' => 'Admin LemburKu',
                'password' => 'password',
                'role' => Role::Admin,
                'is_active' => true,
                'rounding_enabled' => false,
            ],
        );

        User::query()->updateOrCreate(
            ['email' => 'falah@lemburku.test'],
            [
                'name' => 'Falah Lazuardi',
                'password' => 'password',
                'role' => Role::Employee,
                'manager_id' => $admin->id,
                'is_active' => true,
                'rounding_enabled' => false,   // BR-04 default MATI
            ],
        );

        // Pembanding untuk R-2: durasi identik, hak berbeda karena preferensi pembulatan.
        User::query()->updateOrCreate(
            ['email' => 'rekan@lemburku.test'],
            [
                'name' => 'Rekan Setim',
                'password' => 'password',
                'role' => Role::Employee,
                'manager_id' => $admin->id,
                'is_active' => true,
                'rounding_enabled' => true,
            ],
        );
    }
}
