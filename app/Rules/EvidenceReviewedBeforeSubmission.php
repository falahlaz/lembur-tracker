<?php

namespace App\Rules;

use App\Enums\OvertimeStatus;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * SY-12 — record hasil sync lahir dengan deep link Kimai sebagai evidence.
 * Link itu membuktikan jam kerja, tetapi SOP §6 mewajibkan SPL dan timesheet,
 * jadi ia pengisi sementara — bukan pengganti.
 *
 * SR-5 — tanpa pagar ini, sync terasa seperti klaim otomatis: user menaikkan
 * status ke "diajukan" dengan evidence yang tidak akan diterima HRD.
 */
class EvidenceReviewedBeforeSubmission implements ValidationRule
{
    public function __construct(
        private readonly bool $needsReview,
        private readonly ?string $evidenceUrl,
        private readonly ?string $placeholderUrl,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->needsReview) {
            return;
        }

        $status = $value instanceof OvertimeStatus ? $value : OvertimeStatus::tryFrom((string) $value);

        if ($status === null || $status === OvertimeStatus::Recorded || $status === OvertimeStatus::Rejected) {
            return;
        }

        // Sudah diganti sendiri oleh user pada form yang sama — flag-nya akan
        // padam saat disimpan, jadi tidak ada alasan menahannya di sini.
        if (filled($this->evidenceUrl) && $this->evidenceUrl !== $this->placeholderUrl) {
            return;
        }

        $fail('Ganti evidence dengan link SPL atau timesheet di OneDrive kamu sebelum mengajukan.');
    }
}
