<?php

namespace App\Services;

use App\Enums\HeadType;
use App\Enums\Processor;
use App\Models\Appointment;
use App\Models\FieldOffice;
use Illuminate\Support\Facades\DB;

/**
 * Step 4 of the CSC RO VIII Appointments Processing Flow Chart.
 *
 *   Field Office headed by Director II (SG 26)   SG >= 26 -> PSED, else Field Office (up to SG 25)
 *   Field Office headed by Caretaker/OIC (SG 22) SG >= 22 -> PSED, else Field Office (below SG 22)
 *   Default (no exception)                       SG >= 21 -> PSED (consolidated), else Field Office (below SG 21)
 */
class AppointmentRouter
{
    public function route(?FieldOffice $office, int $salaryGrade): Processor
    {
        $headType = $office?->head_type ?? HeadType::Standard;

        return $salaryGrade >= $headType->psedThreshold()
            ? Processor::Psed
            : Processor::FieldOffice;
    }

    /** Re-evaluate every appointment of an office after its head type changed. */
    public function rerouteOffice(FieldOffice $office): void
    {
        $threshold = $office->head_type->psedThreshold();

        Appointment::query()
            ->where('field_office_id', $office->id)
            ->update([
                'processed_by' => DB::raw(
                    "CASE WHEN salary_grade >= {$threshold} THEN 'psed' ELSE 'field_office' END"
                ),
            ]);
    }
}
