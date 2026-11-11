<?php

namespace App\Services\Reports;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * IPMR: the Field Office's accomplishment grid. Months run Jan-Jun in E-J and
 * Jul-Dec in L-Q (K and R are the semester columns, S the total). Only the
 * five rows that come from appointments are written; every other indicator
 * belongs to other divisions and the percentages and semester totals are the
 * template's formulas.
 *
 *   5.1  first-time eligibles absorbed, male       -> row 27
 *   5.2  first-time eligibles absorbed, female     -> row 28
 *   6.2.1 appointments received for the month      -> row 33
 *   6a.1 acted upon, received this year            -> row 37
 *   6b.1 acted upon, received before the year      -> row 43
 *
 * A Field Office's report fills the selected month only; the consolidated one
 * (all offices) fills every month from January to the selected one, since the
 * template's earlier months hold one office's figures (see MrapHydrator).
 */
class IpmrHydrator implements ReportHydrator
{
    private const MONTH_COLUMNS = [1 => 'E', 'F', 'G', 'H', 'I', 'J', 'L', 'M', 'N', 'O', 'P', 'Q'];

    public function hydrate(Spreadsheet $spreadsheet, ReportContext $context): void
    {
        $sheet = $spreadsheet->getSheetByName('IPMR 2026') ?? $spreadsheet->getSheet(0);

        $months = $context->isConsolidated() ? range(1, $context->month) : [$context->month];
        foreach ($months as $month) {
            $this->fillMonth($sheet, $context->forMonth($month));
        }

        $sheet->setCellValue('A2', 'Accomplishment Report for '.$context->monthName().' '.$context->year);
        $sheet->setCellValue('A3', 'Office: '.strtoupper($context->officeName()));
    }

    private function fillMonth(Worksheet $sheet, ReportContext $context): void
    {
        $col = self::MONTH_COLUMNS[$context->month];

        $firstTime = $context->appointments()->firstTimeApprovedBetween($context->start, $context->end);
        $acted = fn () => $context->appointments()->actedBetween($context->start, $context->end);

        $values = [
            27 => (clone $firstTime)->where('sex', 'M')->count(),
            28 => (clone $firstTime)->where('sex', 'F')->count(),
            33 => $context->appointments()->receivedBetween($context->start, $context->end)->count(),
            37 => $acted()->where('date_received', '>=', $context->yearStart->toDateString())->count(),
            43 => $acted()->where('date_received', '<', $context->yearStart->toDateString())->count(),
        ];

        foreach ($values as $row => $value) {
            $sheet->setCellValue($col.$row, $value);
        }
    }
}
