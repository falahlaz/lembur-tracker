<?php

namespace Database\Seeders;

use App\Models\OvertimeRule;
use Illuminate\Database\Seeder;

/** BR-24 — versi aturan baseline sesuai SOP lembur MyTelkomsel Native. */
class OvertimeRuleSeeder extends Seeder
{
    public function run(): void
    {
        OvertimeRule::query()->firstOrCreate(
            ['effective_from' => '2020-01-01'],
            [
                'effective_to' => null,
                'tier1_min_minutes' => 240,       // ≥ 4 jam
                'tier1_meal_amount' => 50_000,
                'tier1_leave_minutes' => 240,
                'tier2_min_minutes' => 480,       // ≥ 8 jam
                'tier2_meal_amount' => 100_000,
                'tier2_leave_minutes' => 480,
                'cutoff_day' => 19,
                'expiry_months' => 1,
                'monthly_claim_quota_days' => 3.0,
                'work_start_time' => '09:00:00',
                'work_end_time' => '18:00:00',
                'notes' => 'Aturan baseline SOP lembur project MyTelkomsel Native.',
            ],
        );
    }
}
