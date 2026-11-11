<?php

namespace App\Services\Reports;

use App\Models\Appointment;
use App\Services\Reports\Support\Cells;
use App\Services\Reports\Support\TemplateRows;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Absorption Report: the first-time entrants into government service whose
 * appointments were approved or validated in the month.
 */
class AbsorptionHydrator implements ReportHydrator
{
    private const FIRST_ROW = 13;

    /** Row of "Prepared by / Approved by"; everything from here down is the footer. */
    private const FOOTER_ROW = 40;

    /** The eligibility breakdown at F3:G9 has seven slots before the Total at row 10. */
    private const BREAKDOWN_SLOTS = 7;

    public function hydrate(Spreadsheet $spreadsheet, ReportContext $context): void
    {
        $sheet = $spreadsheet->getSheet(0);
        $sheet->setTitle($context->monthName());

        $rows = $context->appointments()
            ->firstTimeApprovedBetween($context->start, $context->end)
            ->with('fieldOffice')
            ->orderBy('last_name')->orderBy('first_name')->orderBy('id')
            ->get();

        // --- Summary block ------------------------------------------------
        $sheet->setCellValue('C2', strtoupper($context->monthName()).' '.$context->year);
        $sheet->setCellValue('C3', config('psed.regional_office'));
        $sheet->setCellValue('C4', $rows->count());
        $sheet->setCellValue('C5', $rows->where('sex', 'M')->count());
        $sheet->setCellValue('C6', $rows->where('sex', 'F')->count());

        $this->breakdown($sheet, $rows);

        // --- Data table ---------------------------------------------------
        TemplateRows::clear($sheet, self::FIRST_ROW, self::FOOTER_ROW - 1, 'A', 'M');
        TemplateRows::ensureCapacity($sheet, self::FIRST_ROW, self::FOOTER_ROW, $rows->count());

        $last = self::FIRST_ROW + max($rows->count(), 1) - 1;
        TemplateRows::applyRowStyle($sheet, self::FIRST_ROW, self::FIRST_ROW, $last, 'M');

        foreach ($rows->values() as $i => $a) {
            /** @var Appointment $a */
            $r = self::FIRST_ROW + $i;
            $sheet->setCellValue("A{$r}", $i + 1);
            $sheet->setCellValue("B{$r}", $a->full_name);
            $sheet->setCellValue("C{$r}", $a->sex);
            $sheet->setCellValue("D{$r}", Cells::yesNo($a->is_pwd));
            $sheet->setCellValue("E{$r}", $a->agency);
            $sheet->setCellValue("F{$r}", $a->position);
            $sheet->setCellValue("G{$r}", Cells::text($a->level));
            $sheet->setCellValue("H{$r}", Cells::shortDate($a->date_issued ?? $a->date_effective));
            $sheet->setCellValue("I{$r}", $a->nature_of_appointment);
            $sheet->setCellValue("J{$r}", Cells::text($a->eligibility_type));
            $sheet->setCellValue("K{$r}", $a->eligibility_issued_date
                ? Cells::shortDate($a->eligibility_issued_date)
                : Cells::text($a->eligibility_exam_date));
            $sheet->setCellValue("L{$r}", Cells::text($a->csc_action));
            $sheet->setCellValue("M{$r}", $a->fieldOffice->imis_code);
        }

        // --- Signatories (footer moved down by any inserted rows) --------
        $shift = max(0, $rows->count() - (self::FOOTER_ROW - self::FIRST_ROW));
        $signatories = $context->signatories();
        $sign = [
            ['B', $signatories['prepared']['name'] ?? null, $signatories['prepared']['title'] ?? null],
            ['H', $signatories['approved']['name'] ?? null, $signatories['approved']['title'] ?? null],
        ];
        foreach ($sign as [$col, $name, $title]) {
            if ($name) {
                $sheet->setCellValue($col.(44 + $shift), $name);
                $sheet->setCellValue($col.(45 + $shift), $title);
            } else {
                // The template carries Eastern Samar's signatories; an office (or the region) without its own signs by hand.
                $sheet->setCellValue($col.(44 + $shift), null);
                $sheet->setCellValue($col.(45 + $shift), null);
            }
        }
    }

    /** Count per eligibility type at F3:G9, with the Total at G10. */
    private function breakdown($sheet, $rows): void
    {
        for ($r = 3; $r <= 9; $r++) {
            $sheet->setCellValue("F{$r}", null);
            $sheet->setCellValue("G{$r}", null);
        }

        $groups = $rows->groupBy(fn (Appointment $a) => Cells::text($a->eligibility_type))
            ->map->count()
            ->sortByDesc(fn ($count) => $count)
            ->all();

        // Not enough room? Keep the biggest and fold the rest into "Others".
        if (count($groups) > self::BREAKDOWN_SLOTS) {
            $top = array_slice($groups, 0, self::BREAKDOWN_SLOTS - 1, true);
            $rest = array_slice($groups, self::BREAKDOWN_SLOTS - 1, null, true);
            $groups = $top + ['Others ('.count($rest).' eligibility types)' => array_sum($rest)];
        }

        $r = 3;
        foreach ($groups as $type => $count) {
            $sheet->setCellValue("F{$r}", $type);
            $sheet->setCellValue("G{$r}", $count);
            $r++;
        }

        $sheet->setCellValue('F10', 'Total');
        $sheet->setCellValue('G10', $rows->count());
    }
}
