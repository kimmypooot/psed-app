<?php

namespace App\Services\Reports;

use App\Models\Appointment;
use App\Services\Reports\Support\Cells;
use App\Services\Reports\Support\TemplateRows;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * MOA (Monitoring of Appointments): one row per appointment on two sheets,
 * "Plantilla" and "CasualCon" (Casual and Contractual appointments).
 *
 * Which appointments: everything received in the month plus everything
 * acted upon in it (the CSC's own October file lists appointments received
 * in September but acted on in October).
 */
class MoaHydrator implements ReportHydrator
{
    private const FIRST_ROW = 10;

    public function hydrate(Spreadsheet $spreadsheet, ReportContext $context): void
    {
        $appointments = $context->appointments()
            ->where(function ($q) use ($context) {
                $q->whereBetween('date_received', [$context->start->toDateString(), $context->end->toDateString()])
                    ->orWhereBetween('action_date', [$context->start->toDateString(), $context->end->toDateString()]);
            })
            ->with('fieldOffice')
            ->orderBy('date_received')->orderBy('last_name')->orderBy('first_name')->orderBy('id')
            ->get();

        [$casual, $plantilla] = $appointments->partition(fn (Appointment $a) => $a->isCasualContractual());

        $this->fill(
            $spreadsheet->getSheetByName('Plantilla'),
            $context,
            $plantilla,
            footerRow: 275,
            lastColumn: 'AK',
            title: '%d APPOINTMENTS DATABASE  (PLANTILLA)',
            row: fn (Appointment $a) => $this->plantillaRow($a),
        );

        $this->fill(
            $spreadsheet->getSheetByName('CasualCon'),
            $context,
            $casual,
            footerRow: 82,
            lastColumn: 'AB',
            title: '%d APPOINTMENTS DATABASE  (CASUAL/CONTRACTUAL)',
            row: fn (Appointment $a) => $this->casualConRow($a),
        );
    }

    /**
     * @param  Collection<int, Appointment>  $rows
     * @param  callable(Appointment): array<string, mixed>  $row  column letter => value
     */
    private function fill(Worksheet $sheet, ReportContext $context, Collection $rows, int $footerRow, string $lastColumn, string $title, callable $row): void
    {
        $sheet->setCellValue('A1', sprintf($title, $context->year));
        $sheet->setCellValue('C3', $context->officeName());
        $sheet->setCellValue('C4', strtoupper($context->monthName()).', '.$context->year);

        // Wipe the template's sample rows, then make room before writing.
        TemplateRows::clear($sheet, self::FIRST_ROW, $footerRow - 1, 'A', $lastColumn);
        TemplateRows::ensureCapacity($sheet, self::FIRST_ROW, $footerRow, $rows->count());

        $last = self::FIRST_ROW + max($rows->count(), 1) - 1;
        TemplateRows::applyRowStyle($sheet, self::FIRST_ROW, self::FIRST_ROW, $last, $lastColumn);

        foreach ($rows->values() as $i => $appointment) {
            foreach ($row($appointment) as $column => $value) {
                $sheet->setCellValue($column.(self::FIRST_ROW + $i), $value);
            }
        }
    }

    /** The columns the two sheets share, A to S. */
    private function common(Appointment $a): array
    {
        return [
            'A' => $a->fieldOffice->name,
            'B' => $a->agency,
            'C' => Cells::text($a->sector),
            'D' => Cells::date($a->date_received),
            'E' => Cells::time($a->time_received),
            'F' => Cells::text($a->received_by),
            'G' => Cells::date($a->date_issued),
            'H' => Cells::date($a->date_effective),
            'I' => $a->last_name,
            'J' => $a->first_name,
            'K' => Cells::text($a->name_extension),
            'L' => Cells::text($a->middle_name),
            'M' => $a->sex === 'M' ? 'X' : null,
            'N' => $a->sex === 'F' ? 'X' : null,
            'O' => $a->position,
            'P' => $a->salary_grade,
            'Q' => Cells::text($a->level),
            'R' => $a->employment_status,
            'S' => $a->nature_of_appointment,
        ];
    }

    private function plantillaRow(Appointment $a): array
    {
        $hasEligibility = filled($a->eligibility_type) && $a->eligibility_type !== Cells::NA;

        return $this->common($a) + [
            'T' => Cells::dateRange($a->publication_start, $a->publication_end),
            'U' => Cells::text($a->publication_mode),
            'V' => Cells::text($a->eligibility_type),
            'W' => Cells::yesNo($a->is_first_time_entrant),
            'X' => $hasEligibility ? Cells::text($a->eligibility_exam_date) : Cells::NA,
            'Y' => $hasEligibility ? Cells::text($a->eligibility_exam_place) : Cells::NA,
            'Z' => $hasEligibility ? Cells::date($a->eligibility_issued_date) : Cells::NA,
            'AA' => $hasEligibility ? Cells::text($a->eligibility_issued_place) : Cells::NA,
            'AB' => $hasEligibility ? Cells::date($a->eligibility_validity) : Cells::NA,
            'AC' => Cells::yesNo($a->is_pwd),
            'AD' => Cells::yesNo($a->is_indigenous),
            'AE' => Cells::yesNo($a->is_solo_parent),
            'AF' => Cells::text($a->csc_action),
            'AG' => Cells::text($a->action_officer),
            'AH' => Cells::date($a->action_date),
            'AI' => Cells::date($a->release_date),
            'AJ' => Cells::text($a->pending_reason),
            'AK' => Cells::text($a->disapproval_reason),
        ];
    }

    private function casualConRow(Appointment $a): array
    {
        return $this->common($a) + [
            'T' => Cells::yesNo($a->is_pwd),
            'U' => Cells::yesNo($a->is_indigenous),
            'V' => Cells::yesNo($a->is_solo_parent),
            'W' => Cells::text($a->csc_action),
            'X' => Cells::text($a->action_officer),
            'Y' => Cells::date($a->action_date),
            'Z' => Cells::date($a->release_date),
            'AA' => Cells::text($a->pending_reason),
            'AB' => Cells::text($a->disapproval_reason),
        ];
    }
}
