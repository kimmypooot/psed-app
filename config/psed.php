<?php

return [

    // Where agencies:sync reads the Directory of Government Agencies (connection: psed_directory).
    'agency_directory' => [
        'table' => env('PSED_DIRECTORY_TABLE', 'agency_directory'),
        // Where the directory is managed (PSED), linked from Settings > Agencies.
        'url' => env('PSED_DIRECTORY_URL'),
    ],

    // Monthly reports Field Offices submit to PSED: due on this day of the following month.
    'reports' => ['due_day' => env('PSED_REPORT_DUE_DAY', 5)],

    // Shown in the About dialog and the sidebar footer.
    'version' => env('PSED_VERSION', '0.1.0'),

    /*
    |--------------------------------------------------------------------------
    | Excel report templates
    |--------------------------------------------------------------------------
    | Blank, officially formatted .xlsx files live in $templates_path. Each
    | report type is matched by a glob, so "FOES MRAP - September 2026.xlsx"
    | and next month's file are both found, whatever the office prefix. When
    | several match, the most recently modified one wins.
    */
    'templates_path' => storage_path('app/templates'),

    'templates' => [
        'ipmr' => '*IPMR*.xlsx',
        'mrap' => '*MRAP*.xlsx',
        'moa' => '*MOA*.xlsx',
        'absorption' => 'AbsorptionRate*.xlsx',
    ],

    /*
    | Employment statuses that go to the "CasualCon" sheet of the MOA.
    | Everything else is reported in the "Plantilla" sheet.
    */
    'casual_contractual_statuses' => ['Casual', 'Contractual'],

    /*
    | CSC actions that count as the appointment having been acted upon,
    | and the subset that counts as approved (used by Absorption/IPMR 5.x).
    */
    'final_actions' => ['Approved', 'Disapproved', 'Invalidated', 'Validated'],
    'approved_actions' => ['Approved', 'Validated'],
    'rejected_actions' => ['Disapproved', 'Invalidated'],

    /*
    | The Sangguniang Kabataan Official Eligibility (SKOE), as the CSC's list spells it, and the SK
    | positions it is granted for. Choosing it on the form asks which position the appointee held.
    */
    'skoe' => [
        'eligibility' => 'Sangguniang Kabataan Official Eligibility (SKOE)',
        'positions' => ['SK Official', 'SK Secretary', 'SK Treasurer'],
    ],

    'regional_office' => 'VIII',

    // IMIS sends each person's unit at sign-in. Employees of this unit are CARES administrators;
    // employees of a Field or Satellite Office are matched to that office by its IMIS code.
    'imis' => [
        'psed_unit_code' => env('IMIS_PSED_UNIT_CODE', 'PSED'),
        // The Office of the Regional Director: its Director IV and Director III act on what PSED processes.
        'regional_director_unit_code' => env('IMIS_REGIONAL_DIRECTOR_UNIT_CODE', 'ORD'),
    ],
];
