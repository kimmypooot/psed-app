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
 * The balance carried in from the year before (A's January value, and section G: appointments
 *  unacted as of 31 December) is the office's opening balance, set by PSED (OpeningBalances), or
 *  worked out from the appointments when PSED set none.
 *
 *   A   beginning balance, January only            -> row 6
 *   G.1 unacted as of 31 December                  -> row 28
 *   G.2 of those, still unacted at the month start -> row 29
 *   G.3 acted from them during the month           -> row 30
 *   G.4 still unacted at the month end             -> row 31
 */
class MrapHydrator implements ReportHydrator
{
    public function hydrate(Spreadsheet $spreadsheet, ReportContext $context): void
    {
        $sheet = $spreadsheet->getSheet(0);

        $months = $context->monthsToFill();

        // Every figure of every month in one pass over the appointments (see MonthlyFigures).
        $figures = MonthlyFigures::compute($context, $months);

        foreach ($months as $month) {
            $this->fillMonth($sheet, $month, $figures[$month]);
        }

        $this->header($sheet, $context);
    }

    /** @param  array<string, int>  $f  the month's MonthlyFigures */
    private function fillMonth(Worksheet $sheet, int $month, array $f): void
    {
        $col = chr(ord('E') + $month - 1);

        $values = [
            7 => $f['received'],
            9 => $f['eligible'],
            10 => $f['received'] - $f['eligible'],
            11 => $f['opening_unacted'],
            12 => $f['received'],
            15 => $f['acted'],
            19 => $f['closing_unacted_prior'],
            20 => $f['closing_unacted_received'],
            25 => $f['rejected'],
            // G: the backlog carried in from the year before, worked down by what is acted from it.
            28 => $f['balance'],
            29 => $f['backlog_start'],
            30 => $f['backlog_acted'],
        ];

        // A. The year's Beginning Balance is January's alone: the other months continue from the ending balance.
        if ($month === 1) {
            $values[6] = $f['balance'];
        }

        // G.4 is a formula in the template's first column; keep it where it is one.
        if (! $sheet->getCell($col.'31')->isFormula()) {
            $values[31] = $f['backlog_end'];
        }

        foreach ($values as $row => $value) {
            $sheet->setCellValue($col.$row, $value);
        }
    }

    private function header(Worksheet $sheet, ReportContext $context): void
    {
        $office = $context->office;

        $sheet->setCellValue('A2', $context->isRange() ? 'for the period of '.strtoupper($context->periodLabel()) : 'for the month of '.strtoupper($context->monthName()).' '.$context->year);
        $sheet->setCellValue('A3', 'Name of Field Office:  '.strtoupper($context->officeName()).($office?->location ? ', '.$office->location : ''));

        // Section G is about the year before the one reported (the template names the year it was made for).
        $carriedFrom = $context->year - 1;
        foreach (['B27', 'D28'] as $cell) {
            $text = $sheet->getCell($cell)->getValue();
            if (is_string($text)) {
                $sheet->setCellValue($cell, preg_replace(['/December 31, \d{4}/', '/February, \d{4}/'], ["December 31, {$carriedFrom}", "February, {$context->year}"], $text));
            }
        }

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
