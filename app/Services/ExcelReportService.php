<?php

namespace App\Services;

use App\Enums\ReportType;
use App\Models\FieldOffice;
use App\Services\Reports\AbsorptionHydrator;
use App\Services\Reports\IpmrHydrator;
use App\Services\Reports\MoaHydrator;
use App\Services\Reports\MrapHydrator;
use App\Services\Reports\ReportContext;
use App\Services\Reports\ReportHydrator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Template hydration: the official, blank .xlsx for each report (kept in
 * storage/app/templates) is opened with PhpSpreadsheet, the month's data is
 * written into its cells, and the result is saved as a new file. The
 * workbook is never built from scratch, so the merged cells, headers,
 * logos, fonts, borders, formulas and validations of the CSC's own format
 * all survive.
 *
 * A null office produces the consolidated report of every Field Office.
 *
 *   $file = app(ExcelReportService::class)->generate(ReportType::Moa, $office, 2026, 10);
 *   return response()->download($file['path'], $file['name'])->deleteFileAfterSend();
 */
class ExcelReportService
{
    /**
     * @return array{path: string, name: string} a temporary file to send, then delete
     */
    public function generate(ReportType $type, ?FieldOffice $office, int $year, int $month): array
    {
        $this->allowEnoughMemory();

        $context = new ReportContext($office, $year, $month);

        // 1. Open the existing template (never a blank workbook).
        $spreadsheet = IOFactory::load($this->templatePath($type));

        // 2-5. Query the month's records and write them into the template's
        // cells; list-style reports insert rows when the data outgrows the
        // template, which pushes the footer and signatures down intact.
        $this->hydrator($type)->hydrate($spreadsheet, $context);

        $spreadsheet->setActiveSheetIndex(0);

        // 6. Save to a temporary file for the controller to download.
        $path = tempnam(sys_get_temp_dir(), 'cares_').'.xlsx';
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        // Keep the template's formulas live, with calculated values stored
        // so previewers that do not calculate (mail, phones) still show them.
        $writer->setPreCalculateFormulas(true);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        return ['path' => $path, 'name' => $type->downloadName($office, $year, $month)];
    }

    /** The newest template file matching the report's pattern. */
    public function templatePath(ReportType $type): string
    {
        $pattern = config('psed.templates_path').DIRECTORY_SEPARATOR.config("psed.templates.{$type->value}");
        $matches = glob($pattern) ?: [];

        // Excel's own lock files ("~$name.xlsx") are not templates.
        $matches = array_values(array_filter($matches, fn ($p) => ! str_starts_with(basename($p), '~$')));

        if ($matches === []) {
            throw new RuntimeException(sprintf(
                'The %s template was not found. Place the blank .xlsx matching "%s" in %s.',
                $type->label(),
                config("psed.templates.{$type->value}"),
                config('psed.templates_path'),
            ));
        }

        usort($matches, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return $matches[0];
    }

    /**
     * The templates are large workbooks (the IPMR's sheet is declared out to
     * column XFD), and PhpSpreadsheet holds a whole one in memory, so give a
     * report request room even when php.ini's limit is the 128M default.
     */
    public function allowEnoughMemory(): void
    {
        $limit = ini_get('memory_limit');

        if ($limit === '-1') {
            return;
        }

        $bytes = (int) $limit * match (strtoupper(substr($limit, -1))) {
            'G' => 1024 ** 3,
            'M' => 1024 ** 2,
            'K' => 1024,
            default => 1,
        };

        if ($bytes < 1024 ** 3) {
            ini_set('memory_limit', '1G');
        }
    }

    private function hydrator(ReportType $type): ReportHydrator
    {
        return match ($type) {
            ReportType::Ipmr => new IpmrHydrator,
            ReportType::Mrap => new MrapHydrator,
            ReportType::Moa => new MoaHydrator,
            ReportType::Absorption => new AbsorptionHydrator,
        };
    }
}
