<?php

namespace App\Services\Reports;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Enhanced MRAP: a fixed grid of indicators, one column per month (Jan = E ...
 * Dec = P). Only the inputs of the month's column are written; the template's
 * own formulas (beginning balance, totals, percentages, ending balance) are
 * left alone and recalculate from them.
 *
 *   B   received for the month                    -> row 7
 *   C.1 received, eligibility verified            -> row 9
 *   C.2 received, no eligibility required         -> row 10
 *   C.3 ready to act, from the beginning balance  -> row 11
 *   C.4 ready to act, received this month         -> row 12
 *   D.1.1 acted this month                        -> row 15
 *   E.2 unacted at month end, from the balance    -> row 19
 *   E.3 unacted at month end, received this month -> row 20
 *   F.1.2 disapproved / invalidated this month    -> row 25
 *
 * A Field Office's report fills the selected month only, leaving the months
 * the office has already reported as they are in its template. The
 * consolidated report (all offices) fills every month from January to the
 * selected one, because the template's earlier months hold one office's
 * figures and the carried-over balances would otherwise be wrong.
 *
 * G.1-G.4 (appointments unacted as of 31 Dec 2025) are not in the data this
 * system holds, so they stay as the template has them.
 */
class MrapHydrator implements ReportHydrator
{
    public function hydrate(Spreadsheet $spreadsheet, ReportContext $context): void
    {
        $sheet = $spreadsheet->getSheet(0);

        $months = $context->isConsolidated() ? range(1, $context->month) : [$context->month];
        foreach ($months as $month) {
            $this->fillMonth($sheet, $context->forMonth($month));
        }

        $this->header($sheet, $context);
    }

    private function fillMonth(Worksheet $sheet, ReportContext $context): void
    {
        $col = chr(ord('E') + $context->month - 1);

        $received = $context->appointments()->receivedBetween($context->start, $context->end);
        $receivedCount = (clone $received)->count();

        $withEligibility = (clone $received)
            ->whereNotNull('eligibility_type')->where('eligibility_type', '!=', 'N/A')->count();

        $beforeMonth = fn () => $context->appointments()->where('date_received', '<', $context->start->toDateString());

        $values = [
            7 => $receivedCount,
            9 => $withEligibility,
            10 => $receivedCount - $withEligibility,
            11 => $beforeMonth()->unactedAsOf($context->start->subDay())->count(),
            12 => $receivedCount,
            15 => $context->appointments()->actedBetween($context->start, $context->end)->count(),
            19 => $beforeMonth()->unactedAsOf($context->end)->count(),
            20 => (clone $received)->unactedAsOf($context->end)->count(),
            25 => $context->appointments()->actedBetween($context->start, $context->end)
                ->whereIn('csc_action', config('psed.rejected_actions'))->count(),
        ];

        foreach ($values as $row => $value) {
            $sheet->setCellValue($col.$row, $value);
        }
    }

    private function header(Worksheet $sheet, ReportContext $context): void
    {
        $office = $context->office;

        $sheet->setCellValue('A2', 'for the month of '.strtoupper($context->monthName()).' '.$context->year);
        $sheet->setCellValue('A3', 'Name of Field Office:  '.strtoupper($context->officeName()).($office?->location ? ', '.$office->location : ''));

        $date = 'Date: '.$context->nextMonthStart()->format('d F Y');

        // The template carries one office's signatories; each report names its own (from IMIS).
        $signatories = $context->signatories();
        $sheet->setCellValue('C37', $signatories['prepared']['name'] ?? null);
        $sheet->setCellValue('C38', $signatories['prepared']['title'] ?? null);
        $sheet->setCellValue('M37', $signatories['approved']['name'] ?? null);
        $sheet->setCellValue('M38', $signatories['approved']['title'] ?? null);
        $sheet->setCellValue('C40', $date);
        $sheet->setCellValue('M40', $date);
    }
}
