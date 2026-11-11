<?php

namespace App\Services;

use App\Enums\Processor;
use App\Enums\ReportType;
use App\Http\Requests\AppointmentRequest;
use App\Models\Appointment;
use App\Models\FieldOffice;
use App\Models\ReferenceValue;
use App\Models\User;
use App\Services\Reports\Support\TemplateRows;
use App\Support\AppointmentRules;
use DateTimeImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Reads appointments from the CSC's MOA form (Monitoring of Appointments): the
 * same layout the MOA report writes, so an office's existing spreadsheet can be
 * brought in, and a report can be read back. The "Plantilla" and "CasualCon"
 * sheets are read from row 10, one appointment per row.
 *
 * Nothing is saved by analyse(): every row is converted and checked with the
 * same rules as the encoding form, duplicates are spotted, and the caller shows
 * the result. import() then saves only the rows that passed.
 */
class AppointmentImporter
{
    public const MAX_ROWS = 2000;

    private const FIRST_ROW = 10;

    /** Columns A to S, the same on both sheets. */
    private const COMMON = [
        'A' => 'office', 'B' => 'agency', 'C' => 'sector', 'D' => 'date_received', 'E' => 'time_received',
        'F' => 'received_by', 'G' => 'date_issued', 'H' => 'date_effective', 'I' => 'last_name', 'J' => 'first_name',
        'K' => 'name_extension', 'L' => 'middle_name', 'M' => 'sex_male', 'N' => 'sex_female', 'O' => 'position',
        'P' => 'salary_grade', 'Q' => 'level', 'R' => 'employment_status', 'S' => 'nature_of_appointment',
    ];

    private const PLANTILLA = [
        'T' => 'publication', 'U' => 'publication_mode', 'V' => 'eligibility_type', 'W' => 'is_first_time_entrant',
        'X' => 'eligibility_exam_date', 'Y' => 'eligibility_exam_place', 'Z' => 'eligibility_issued_date',
        'AA' => 'eligibility_issued_place', 'AB' => 'eligibility_validity', 'AC' => 'is_pwd', 'AD' => 'is_indigenous',
        'AE' => 'is_solo_parent', 'AF' => 'csc_action', 'AG' => 'action_officer', 'AH' => 'action_date',
        'AI' => 'release_date', 'AJ' => 'pending_reason', 'AK' => 'disapproval_reason',
    ];

    private const CASUAL = [
        'T' => 'is_pwd', 'U' => 'is_indigenous', 'V' => 'is_solo_parent', 'W' => 'csc_action', 'X' => 'action_officer',
        'Y' => 'action_date', 'Z' => 'release_date', 'AA' => 'pending_reason', 'AB' => 'disapproval_reason',
    ];

    private const DATE_FIELDS = ['date_received', 'date_issued', 'date_effective', 'eligibility_issued_date', 'eligibility_validity', 'action_date', 'release_date'];

    private const BOOL_FIELDS = ['is_first_time_entrant', 'is_pwd', 'is_indigenous', 'is_solo_parent'];

    private const DATE_FORMATS = ['m/d/Y', 'n/j/Y', 'Y-m-d', 'd-M-y', 'd-M-Y', 'M j, Y', 'F j, Y', 'm-d-Y'];

    /** @var array<string, array<string, string>>|null lower-cased value => the CSC's own spelling, per list */
    private ?array $lists = null;

    /** Problems found in the cell currently being read. */
    private array $cellErrors = [];

    public function __construct(
        private readonly AppointmentRouter $router,
        private readonly ExcelReportService $reports,
        private readonly ImisDirectory $directory,
    ) {}

    /**
     * Reads and checks every row; saves nothing.
     *
     * @param  ?FieldOffice  $office  the office to import into; null (PSED only) reads it from column A of each row
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>}
     *
     * @throws ImportException when the file as a whole cannot be used
     */
    public function analyse(string $path, User $user, ?FieldOffice $office): array
    {
        $this->reports->allowEnoughMemory();

        $book = $this->open($path);
        $offices = FieldOffice::all();
        $rows = [];

        foreach (['Plantilla' => self::PLANTILLA, 'CasualCon' => self::CASUAL] as $sheetName => $extra) {
            $sheet = $book->getSheetByName($sheetName);
            if ($sheet === null) {
                continue;
            }
            $this->assertForm($sheet, $sheetName);

            foreach ($this->readRows($sheet, $sheetName, $extra, $user, $office, $offices) as $row) {
                $rows[] = $row;
            }
            if (count($rows) > self::MAX_ROWS) {
                throw new ImportException('The file has more than '.self::MAX_ROWS.' appointments. Please split it into smaller files.');
            }
        }

        $book->disconnectWorksheets();

        if ($rows === []) {
            throw new ImportException('No appointments were found. Rows start at row 10 of the "Plantilla" and "CasualCon" sheets, and each needs a last and a first name.');
        }

        $rows = $this->markDuplicates($rows);

        return ['rows' => $rows, 'counts' => $this->counts($rows)];
    }

    /**
     * Saves the rows that passed. All or nothing: if saving fails, nothing is kept.
     *
     * @return array<string, int>
     */
    public function import(string $path, User $user, ?FieldOffice $office): array
    {
        $analysis = $this->analyse($path, $user, $office);
        $ready = array_values(array_filter($analysis['rows'], fn (array $r) => $r['ready']));

        DB::transaction(function () use ($ready) {
            foreach ($ready as $row) {
                $appointment = new Appointment($row['data']);
                $appointment->field_office_id = $row['office']->id;
                $appointment->setRelation('fieldOffice', $row['office']);   // for the routing done on save
                $appointment->save();
            }
        });

        return $analysis['counts'] + ['imported' => count($ready)];
    }

    /** A blank copy of the MOA form to fill in: the CSC's template with its sample rows cleared. */
    public function blankForm(): array
    {
        $this->reports->allowEnoughMemory();

        $book = IOFactory::load($this->reports->templatePath(ReportType::Moa));

        foreach ([['Plantilla', 275, 'AK'], ['CasualCon', 82, 'AB']] as [$name, $footer, $last]) {
            $sheet = $book->getSheetByName($name);
            TemplateRows::clear($sheet, self::FIRST_ROW, $footer - 1, 'A', $last);
            $sheet->setCellValue('C3', null);
            $sheet->setCellValue('C4', null);
        }
        $book->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'cares_form_').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);
        $book->disconnectWorksheets();

        return ['path' => $path, 'name' => 'CARES appointments import form.xlsx'];
    }

    // ------------------------------------------------------------------ reading

    private function open(string $path): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $names = $reader->listWorksheetNames($path);
        } catch (\Throwable) {
            throw new ImportException('The file could not be read. Please upload an Excel (.xlsx) file.');
        }

        // Only the two sheets of the form are loaded (the template's hidden lists are left alone).
        $wanted = array_values(array_intersect(['Plantilla', 'CasualCon'], $names));

        if ($wanted === []) {
            throw new ImportException('This is not the MOA form: it has no "Plantilla" or "CasualCon" sheet. Download the import form and copy the appointments into it.');
        }

        try {
            $reader->setLoadSheetsOnly($wanted);

            return $reader->load($path);
        } catch (\Throwable) {
            throw new ImportException('The file could not be read. Please upload an Excel (.xlsx) file.');
        }
    }

    /** The headers sit where the form puts them; a sheet that does not match is some other layout. */
    private function assertForm(Worksheet $sheet, string $name): void
    {
        $header = mb_strtolower(trim((string) $sheet->getCell('I7')->getValue()));

        if (! str_contains($header, 'last')) {
            throw new ImportException("The \"{$name}\" sheet does not follow the MOA form (the last name column should be I). Download the import form and copy the appointments into it.");
        }
    }

    /**
     * @param  array<string, string>  $extra
     * @param  \Illuminate\Support\Collection<int, FieldOffice>  $offices
     * @return list<array<string, mixed>>
     */
    private function readRows(Worksheet $sheet, string $sheetName, array $extra, User $user, ?FieldOffice $fixed, $offices): array
    {
        $columns = self::COMMON + $extra;
        $last = $sheet->getHighestDataRow('I');
        $out = [];

        if ($last < self::FIRST_ROW) {
            return [];
        }

        $grid = $sheet->rangeToArray('A'.self::FIRST_ROW.':'.($sheetName === 'Plantilla' ? 'AK' : 'AB').$last, null, false, false, false);

        foreach ($grid as $i => $cells) {
            $rowNumber = self::FIRST_ROW + $i;
            $raw = [];
            foreach (array_keys($columns) as $n => $letter) {
                $raw[$columns[$letter]] = $cells[$n] ?? null;
            }

            if (str_contains(mb_strtoupper((string) ($raw['office'] ?? '')), 'NOTHING FOLLOWS')) {
                break;
            }
            if ($this->text($raw['last_name']) === null && $this->text($raw['first_name']) === null) {
                continue; // an empty row of the form
            }

            $out[] = $this->convert($raw, $sheetName, $rowNumber, $user, $fixed, $offices);
        }

        return $out;
    }

    // --------------------------------------------------------------- converting

    /**
     * @param  array<string, mixed>  $raw
     * @param  \Illuminate\Support\Collection<int, FieldOffice>  $offices
     * @return array<string, mixed>
     */
    private function convert(array $raw, string $sheet, int $rowNumber, User $user, ?FieldOffice $fixed, $offices): array
    {
        $errors = [];
        $warnings = [];
        $this->cellErrors = [];

        // --- the office -----------------------------------------------------------------
        [$office, $officeProblem, $officeIsError] = $this->resolveOffice($this->text($raw['office']), $fixed, $user, $offices);
        if ($officeProblem !== null) {
            $officeIsError ? $errors[] = $officeProblem : $warnings[] = $officeProblem;
        }

        // --- the sex is a mark under Male or under Female -----------------------------------
        $male = $this->isMark($raw['sex_male']);
        $female = $this->isMark($raw['sex_female']);
        $sex = $male && ! $female ? 'M' : (! $male && $female ? 'F' : null);

        $data = [
            'agency' => $this->text($raw['agency']),
            'sector' => $this->canonical('sector', $this->text($raw['sector'])),
            'date_received' => $this->date($raw['date_received'], 'date received'),
            'time_received' => $this->time($raw['time_received']),
            'received_by' => $this->text($raw['received_by']),
            'date_issued' => $this->date($raw['date_issued'], 'date of issuance'),
            'date_effective' => $this->date($raw['date_effective'], 'date of effectivity'),
            'last_name' => $this->text($raw['last_name']),
            'first_name' => $this->text($raw['first_name']),
            'name_extension' => $this->text($raw['name_extension']),
            'middle_name' => $this->text($raw['middle_name']),
            'sex' => $sex,
            'position' => $this->text($raw['position']),
            'salary_grade' => $this->grade($raw['salary_grade']),
            'level' => $this->level($raw['level']),
            'employment_status' => $this->canonical('employment_status', $this->text($raw['employment_status'])),
            'nature_of_appointment' => $this->canonical('nature_of_appointment', $this->text($raw['nature_of_appointment'])),
        ];

        if ($sex === null) {
            $errors[] = 'Mark the sex with an X under Male or under Female (and only one).';
        }

        // Publication and eligibility exist on the Plantilla sheet only.
        $isPlantilla = $sheet === 'Plantilla';
        [$data['publication_start'], $data['publication_end']] = $isPlantilla ? $this->publication($raw['publication'] ?? null) : [null, null];
        $data += [
            'publication_mode' => $isPlantilla ? $this->canonical('publication_mode', $this->text($raw['publication_mode'] ?? null)) : null,
            'eligibility_type' => $isPlantilla ? $this->canonical('eligibility_type', $this->text($raw['eligibility_type'] ?? null)) : null,
            'is_first_time_entrant' => $isPlantilla && $this->isYes($raw['is_first_time_entrant'] ?? null),
            'eligibility_exam_date' => $isPlantilla ? $this->examDate($raw['eligibility_exam_date'] ?? null) : null,
            'eligibility_exam_place' => $isPlantilla ? $this->text($raw['eligibility_exam_place'] ?? null) : null,
            'eligibility_issued_date' => $isPlantilla ? $this->date($raw['eligibility_issued_date'] ?? null, 'date of eligibility issuance') : null,
            'eligibility_issued_place' => $isPlantilla ? $this->text($raw['eligibility_issued_place'] ?? null) : null,
            'eligibility_validity' => $isPlantilla ? $this->date($raw['eligibility_validity'] ?? null, 'validity of license') : null,
            'is_pwd' => $this->isYes($raw['is_pwd'] ?? null),
            'is_indigenous' => $this->isYes($raw['is_indigenous'] ?? null),
            'is_solo_parent' => $this->isYes($raw['is_solo_parent'] ?? null),
            'csc_action' => $this->action($raw['csc_action'] ?? null),
            'action_officer' => $this->text($raw['action_officer'] ?? null),
            'action_date' => $this->date($raw['action_date'] ?? null, 'date of action'),
            'release_date' => $this->date($raw['release_date'] ?? null, 'date of release'),
            'pending_reason' => $this->text($raw['pending_reason'] ?? null),
            'disapproval_reason' => $this->text($raw['disapproval_reason'] ?? null),
        ];

        $errors = [...$errors, ...$this->cellErrors];

        // The MOA form has no column for the SK position or the K-12 mark, so they are added by editing the appointment.
        if ($data['eligibility_type'] === config('psed.skoe.eligibility')) {
            $warnings[] = 'SKOE: the SK position (SK Official, SK Secretary or SK Treasurer) is not in the MOA form. Open the appointment after the import to choose it.';
        }

        // The CasualCon sheet is for Casual and Contractual appointments only.
        if ($sheet === 'CasualCon' && ! in_array($data['employment_status'], config('psed.casual_contractual_statuses'), true) && $data['employment_status'] !== null) {
            $warnings[] = "Listed on the CasualCon sheet but the status is \"{$data['employment_status']}\", so the MOA report will list it under Plantilla.";
        }

        // --- who records the decision: the office the flow chart routes the appointment to ----------
        $processor = null;
        if ($office !== null && is_int($data['salary_grade'])) {
            $processor = $this->router->route($office, $data['salary_grade']);

            $hasAction = array_filter(Arr::only($data, AppointmentRequest::ACTION_FIELDS)) !== [];

            if ($hasAction && ! Gate::forUser($user)->allows('record-action', [$office, $processor])) {
                $data = Arr::except($data, AppointmentRequest::ACTION_FIELDS) + array_fill_keys(AppointmentRequest::ACTION_FIELDS, null);
                $warnings[] = 'The CSC action was not imported: PSED processes this appointment, so PSED records it.';
            }
        }

        // --- who received it is an IMIS employee of a Field Office ----------------------------------
        $data['received_by_imis_id'] = null;
        if ($data['received_by'] !== null && $this->directory->configured()) {
            try {
                $person = $this->directory->match($data['received_by'], $user->seesAllOffices() ? null : $user->fieldOffice?->imis_code);

                if ($person !== null) {
                    $data['received_by'] = $person['name'];
                    $data['received_by_imis_id'] = $person['id'];
                } else {
                    $warnings[] = "The \"Received by\" name \"{$data['received_by']}\" is not (uniquely) among the IMIS employees, so it was kept as typed.";
                }
            } catch (DirectoryUnavailable) {
                $warnings[] = 'IMIS could not be reached, so "Received by" was kept as typed.';
            }
        }

        // --- the action officer is an IMIS employee ---------------------------------------------
        // Match the written name to one of the people who may act on this appointment (the regional office's
        // Director IV and III for what PSED processes, the Field Office's people for the rest); if it is
        // not (uniquely) there, keep it as typed and say so.
        $data['action_officer_imis_id'] = $data['action_officer_imis_id'] ?? null;
        if ($data['action_officer'] !== null && $this->directory->configured()) {
            try {
                $person = $processor === Processor::Psed
                    ? $this->directory->matchDirector($data['action_officer'])
                    : $this->directory->match($data['action_officer'], $user->seesAllOffices() ? null : $user->fieldOffice?->imis_code);

                if ($person !== null) {
                    $data['action_officer'] = $person['name'];
                    $data['action_officer_imis_id'] = $person['id'];
                } else {
                    $warnings[] = "The action officer \"{$data['action_officer']}\" is not (uniquely) among the IMIS employees, so it was kept as typed.";
                }
            } catch (DirectoryUnavailable) {
                $warnings[] = 'IMIS could not be reached, so the action officer was kept as typed.';
            }
        }

        // --- the same rules as the encoding form ---------------------------------------------
        $validator = Validator::make($data, AppointmentRules::fields(), AppointmentRules::messages());
        if ($validator->fails()) {
            $errors = [...$errors, ...$validator->errors()->all()];
        }
        $errors = array_values(array_unique($errors));

        return [
            'sheet' => $sheet,
            'row' => $rowNumber,
            'name' => trim(($data['last_name'] ?? '?').', '.($data['first_name'] ?? '')),
            'data' => $data,
            'office' => $office,
            'errors' => $errors,
            'warnings' => $warnings,
            'duplicate' => false,
            'ready' => $errors === [] && $office !== null,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, FieldOffice>  $offices
     * @return array{0: ?FieldOffice, 1: ?string, 2: bool} the office, a problem to report, and whether it is an error (not just a warning)
     */
    private function resolveOffice(?string $cell, ?FieldOffice $fixed, User $user, $offices): array
    {
        $named = $cell === null ? null : $offices->first(fn (FieldOffice $o) => mb_strtolower($o->name) === mb_strtolower($cell) || mb_strtolower($o->imis_code) === mb_strtolower($cell));

        if ($fixed !== null) {
            $mismatch = $cell !== null && $named?->id !== $fixed->id;

            if (! $mismatch) {
                return [$fixed, null, false];
            }

            // A Field Office may only import its own; PSED chose this office on purpose, so it is told but not blocked.
            return $user->seesAllOffices()
                ? [$fixed, "Column A names \"{$cell}\" but the file is being imported into {$fixed->name}.", false]
                : [null, "Column A names \"{$cell}\". You can only import appointments of {$fixed->name}.", true];
        }

        if ($cell === null) {
            return [null, 'The Field Office (column A) is empty.', true];
        }

        return $named !== null
            ? [$named, null, false]
            : [null, "Column A names \"{$cell}\", which is not a Field Office in CARES.", true];
    }

    // ----------------------------------------------------------------- duplicates

    /**
     * An appointment already in CARES (same office, name, position and date received), or repeated
     * in the file, is a duplicate: it is left out, not imported twice.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function markDuplicates(array $rows): array
    {
        $key = fn (int $officeId, array $d) => implode('|', [$officeId, mb_strtolower((string) $d['last_name']), mb_strtolower((string) $d['first_name']), mb_strtolower((string) $d['position']), $d['date_received']]);

        $candidates = array_filter($rows, fn ($r) => $r['office'] !== null && $r['data']['date_received'] !== null);
        $existing = [];

        if ($candidates !== []) {
            $dates = array_column(array_column($candidates, 'data'), 'date_received');
            Appointment::query()
                ->whereIn('field_office_id', array_unique(array_map(fn ($r) => $r['office']->id, $candidates)))
                ->whereDate('date_received', '>=', min($dates))
                ->whereDate('date_received', '<=', max($dates))
                ->get(['field_office_id', 'last_name', 'first_name', 'position', 'date_received'])
                ->each(function (Appointment $a) use (&$existing, $key) {
                    $existing[$key($a->field_office_id, ['last_name' => $a->last_name, 'first_name' => $a->first_name, 'position' => $a->position, 'date_received' => $a->date_received->toDateString()])] = true;
                });
        }

        $seen = [];
        foreach ($rows as &$row) {
            // A row with no office or date cannot be compared, and it already has an error.
            if ($row['office'] === null || $row['data']['date_received'] === null) {
                continue;
            }
            $k = $key($row['office']->id, $row['data']);

            if (isset($existing[$k])) {
                $row['duplicate'] = true;
                $row['warnings'][] = 'Already in CARES: same office, name, position and date received.';
            } elseif (isset($seen[$k])) {
                $row['duplicate'] = true;
                $row['warnings'][] = 'Repeated in this file (see row '.$seen[$k].').';
            } else {
                $seen[$k] = $row['row'];
            }

            $row['ready'] = $row['ready'] && ! $row['duplicate'];
        }
        unset($row);

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function counts(array $rows): array
    {
        return [
            'rows' => count($rows),
            'ready' => count(array_filter($rows, fn ($r) => $r['ready'])),
            'duplicates' => count(array_filter($rows, fn ($r) => $r['duplicate'])),
            'invalid' => count(array_filter($rows, fn ($r) => $r['errors'] !== [])),
            'plantilla' => count(array_filter($rows, fn ($r) => $r['sheet'] === 'Plantilla')),
            'casual' => count(array_filter($rows, fn ($r) => $r['sheet'] === 'CasualCon')),
        ];
    }

    // ------------------------------------------------------------ cell conversion

    /** Trimmed text; the form's "N/A" and empty cells mean "nothing". */
    private function text(mixed $value): ?string
    {
        if ($value === null || is_bool($value)) {
            return null;
        }

        $text = is_float($value) && floor($value) === $value ? (string) (int) $value : trim((string) $value);

        return ($text === '' || strcasecmp($text, 'N/A') === 0) ? null : $text;
    }

    private function isMark(mixed $value): bool
    {
        return strtoupper((string) $this->text($value)) === 'X';
    }

    private function isYes(mixed $value): bool
    {
        return in_array(strtolower((string) $this->text($value)), ['yes', 'y', 'true', '1', 'x'], true);
    }

    /** An Excel date (serial number) or typed date, as "Y-m-d"; null for "N/A" or empty. */
    private function date(mixed $value, string $what): ?string
    {
        if ($value === null || (is_string($value) && $this->text($value) === null)) {
            return null;
        }

        if (is_numeric($value)) {
            $year = (int) Date::excelToDateTimeObject((float) $value)->format('Y');

            if ($year < 1900 || $year > 2100) {
                $this->cellErrors[] = "The {$what} is not a valid date.";

                return null;
            }

            return Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }

        $parsed = $this->parseDate(trim((string) $value));
        if ($parsed === null) {
            $this->cellErrors[] = "The {$what} (\"{$value}\") is not a date I can read. Use mm/dd/yyyy.";
        }

        return $parsed;
    }

    private function parseDate(string $text): ?string
    {
        foreach (self::DATE_FORMATS as $format) {
            $d = DateTimeImmutable::createFromFormat('!'.$format, $text);
            $problems = DateTimeImmutable::getLastErrors();

            if ($d !== false && (! $problems || ($problems['warning_count'] === 0 && $problems['error_count'] === 0))
                && (int) $d->format('Y') >= 1900 && (int) $d->format('Y') <= 2100) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }

    /** The exam date is kept as written ("mm/dd/yyyy" or a range), as the form keeps it. */
    private function examDate(mixed $value): ?string
    {
        if (is_numeric($value) && (float) $value > 1) {
            return Date::excelToDateTimeObject((float) $value)->format('m/d/Y');
        }

        return $this->text($value);
    }

    /** A time as "H:i": an Excel time, or typed as 14:30:00, 14:30 or 2:30 PM. */
    private function time(mixed $value): ?string
    {
        if ($value === null || (is_string($value) && $this->text($value) === null)) {
            return null;
        }

        if (is_numeric($value) && (float) $value >= 0 && (float) $value < 1) {
            return gmdate('H:i', (int) round((float) $value * 86400));
        }

        $stamp = strtotime((string) $value);
        if ($stamp === false) {
            $this->cellErrors[] = "The time received (\"{$value}\") is not a time I can read.";

            return null;
        }

        return date('H:i', $stamp);
    }

    private function grade(mixed $value): int|string|null
    {
        $text = $this->text($value);

        return $text !== null && preg_match('/^\D*(\d{1,2})\D*$/', $text, $m) === 1 ? (int) $m[1] : $text;
    }

    private function level(mixed $value): ?string
    {
        $text = $this->text($value);

        if ($text !== null && preg_match('/^\s*([123])/', $text, $m) === 1) {
            return ['1' => '1st', '2' => '2nd', '3' => '3rd'][$m[1]];
        }

        return $text;
    }

    private function action(mixed $value): ?string
    {
        $text = $this->text($value);
        if ($text === null) {
            return null;
        }

        foreach (['Approved', 'Disapproved', 'Invalidated', 'Pending', 'Validated'] as $known) {
            if (strcasecmp($text, $known) === 0) {
                return $known;
            }
        }

        $this->cellErrors[] = "The CSC action \"{$text}\" is not one of Approved, Disapproved, Invalidated, Pending or Validated.";

        return null;
    }

    /** "mm/dd/yyyy to mm/dd/yyyy" => [start, end]. */
    private function publication(mixed $value): array
    {
        $text = $this->text($value);
        if ($text === null) {
            return [null, null];
        }

        $parts = preg_split('/\s+to\s+/i', $text);
        $dates = array_map(fn ($p) => $this->parseDate(trim($p)), $parts);

        if (in_array(null, $dates, true)) {
            $this->cellErrors[] = "The publication dates (\"{$text}\") are not dates I can read. Use mm/dd/yyyy to mm/dd/yyyy.";

            return [null, null];
        }

        return [$dates[0], $dates[1] ?? null];
    }

    /** The CSC's own spelling of a listed value ("casual" => "Casual"); anything else is kept as typed. */
    private function canonical(string $list, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $this->lists ??= ReferenceValue::whereIn('type', ['sector', 'employment_status', 'nature_of_appointment', 'publication_mode', 'eligibility_type'])
            ->get()->groupBy('type')
            ->map(fn ($group) => $group->mapWithKeys(fn ($r) => [mb_strtolower($r->value) => $r->value])->all())
            ->all();

        return $this->lists[$list][mb_strtolower($value)] ?? $value;
    }
}
