<?php

namespace App\Services\Reports;

use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Pours one report's data into the already-open official template. A
 * hydrator only ever writes values into cells (and clones row styles when
 * the data outgrows the template); it never rebuilds the layout, merged
 * cells, headers or formulas.
 */
interface ReportHydrator
{
    public function hydrate(Spreadsheet $spreadsheet, ReportContext $context): void;
}
