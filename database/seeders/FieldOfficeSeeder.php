<?php

namespace Database\Seeders;

use App\Enums\HeadType;
use App\Models\FieldOffice;
use App\Models\ReferenceValue;
use Illuminate\Database\Seeder;

/**
 * Field Offices come from the MOA template's RefData list (seeded into
 * reference_values by ReferenceDataSeeder), so run that first. Each is
 * tied to its IMIS organizational unit code, which is the one code used
 * everywhere: to scope a signed-in Field Office user to their own office,
 * and on the reports.
 */
class FieldOfficeSeeder extends Seeder
{
    /** MOA/report name => IMIS organizational unit code. */
    private const IMIS_CODES = [
        'Field Office-Biliran' => 'BFO',
        'Field Office-Eastern Samar' => 'ESFO',
        'Field Office-Leyte I' => 'LFO-I',
        'Field Office-Leyte II' => 'LFO-II',
        'Field Office-Northern Samar' => 'NSFO',
        'Field Office-Samar' => 'SFO',
        'Field Office-Southern Leyte' => 'SLFO',
        'Satellite Office-Western Leyte' => 'WLSO',
    ];

    /** Offices whose head type is known; the rest default to the standard case. Admins can change it in Settings. */
    private const HEADS = [
        'Field Office-Eastern Samar' => HeadType::DirectorII,
    ];

    public function run(): void
    {
        $names = ReferenceValue::where('type', 'field_office')->pluck('value');

        foreach ($names as $name) {
            // An office without an IMIS code could not be matched at sign-in, so it is not created.
            $code = self::IMIS_CODES[$name] ?? null;
            if ($code === null) {
                $this->command?->warn("No IMIS code known for \"{$name}\"; skipped.");

                continue;
            }

            $office = FieldOffice::firstOrNew(['name' => $name]);
            $office->imis_code = $code;
            $office->head_type ??= (self::HEADS[$name] ?? HeadType::Standard);
            $office->save();
        }

        FieldOffice::where('name', 'Field Office-Eastern Samar')->update([
            'location' => 'Borongan City',
            'prepared_by_name' => 'RYAN JOSE M. ESTOQUE',
            'prepared_by_title' => 'Senior Personnel Specialist',
            'approved_by_name' => 'REY ALBERT B. UY',
            'approved_by_title' => 'Director II',
        ]);
    }
}
