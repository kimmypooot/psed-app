<?php

namespace Database\Seeders;

use App\Models\ReferenceValue;
use Illuminate\Database\Seeder;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Loads the dropdown lists from the hidden "RefData" sheet of the official MOA
 * template, so the system uses the exact wording CSC already uses.
 */
class ReferenceDataSeeder extends Seeder
{
    /** RefData column => reference_values.type */
    private const COLUMNS = [
        'A' => 'field_office',
        'B' => 'sector',
        'D' => 'employment_status',
        'E' => 'nature_of_appointment',
        'F' => 'eligibility_type',
        'G' => 'publication_mode',
        'H' => 'csc_action',
        'I' => 'level',
        'J' => 'position',
    ];

    public function run(): void
    {
        $files = glob(config('psed.templates_path').DIRECTORY_SEPARATOR.config('psed.templates.moa'));

        if (! $files) {
            $this->command?->warn('MOA template not found in storage/app/templates; reference lists not seeded.');

            return;
        }

        $reader = IOFactory::createReaderForFile($files[0]);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly('RefData');
        $sheet = $reader->load($files[0])->getSheetByName('RefData');

        $now = now();

        foreach (self::COLUMNS as $col => $type) {
            $values = [];
            for ($row = 2, $max = $sheet->getHighestDataRow($col); $row <= $max; $row++) {
                $v = trim((string) $sheet->getCell($col.$row)->getValue());
                if ($v !== '') {
                    $values[$v] = ['type' => $type, 'value' => $v, 'created_at' => $now, 'updated_at' => $now];
                }
            }

            foreach (array_chunk(array_values($values), 500) as $chunk) {
                ReferenceValue::upsert($chunk, ['type', 'value'], ['updated_at']);
            }
        }
    }
}
