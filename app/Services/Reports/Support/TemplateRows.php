<?php

namespace App\Services\Reports\Support;

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Helpers for list-style templates: a block of pre-formatted data rows
 * followed by a footer (the "NOTHING FOLLOWS" line, or the signatures).
 */
final class TemplateRows
{
    /**
     * Makes sure $needed data rows fit between $firstRow and the footer.
     * When they do not, rows are inserted just above the footer; PhpSpreadsheet
     * moves everything below down with it (the merged footer, signatures and
     * any formulas that point at them), so nothing is overwritten.
     *
     * @return int number of rows inserted
     */
    public static function ensureCapacity(Worksheet $sheet, int $firstRow, int $footerRow, int $needed): int
    {
        $capacity = $footerRow - $firstRow;

        if ($needed <= $capacity) {
            return 0;
        }

        $extra = $needed - $capacity;
        $sheet->insertNewRowBefore($footerRow, $extra);

        return $extra;
    }

    /** Empties the values (not the styles) of a block of cells. */
    public static function clear(Worksheet $sheet, int $fromRow, int $toRow, string $firstColumn, string $lastColumn): void
    {
        $from = self::columnIndex($firstColumn);
        $to = self::columnIndex($lastColumn);

        for ($row = $fromRow; $row <= $toRow; $row++) {
            for ($col = $from; $col <= $to; $col++) {
                $sheet->getCell([$col, $row])->setValue(null);
            }
        }
    }

    /**
     * Gives rows $fromRow..$toRow the look of the model row (borders, date
     * formats, alignment, height). The template only formats the rows it
     * ships with data in, so every row that is written gets this.
     */
    public static function applyRowStyle(Worksheet $sheet, int $modelRow, int $fromRow, int $toRow, string $lastColumn): void
    {
        $from = max($fromRow, $modelRow + 1);

        if ($from > $toRow) {
            return;
        }

        // Column by column: duplicateStyle() stamps ONE cell's style over its
        // whole range, so a row-wide copy would flatten every column to A's look.
        for ($col = 1, $max = self::columnIndex($lastColumn); $col <= $max; $col++) {
            $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $sheet->duplicateStyle($sheet->getStyle("{$letter}{$modelRow}"), "{$letter}{$from}:{$letter}{$toRow}");
        }

        $height = $sheet->getRowDimension($modelRow)->getRowHeight();
        for ($row = $from; $row <= $toRow; $row++) {
            $sheet->getRowDimension($row)->setRowHeight($height);
        }
    }

    public static function columnIndex(string $letters): int
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($letters);
    }
}
