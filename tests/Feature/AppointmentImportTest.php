<?php

namespace Tests\Feature;

use App\Enums\HeadType;
use App\Enums\ReportType;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\FieldOffice;
use App\Models\ReferenceValue;
use App\Models\User;
use App\Services\AppointmentImporter;
use App\Services\ExcelReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\FakesImisDirectory;
use Tests\TestCase;

class AppointmentImportTest extends TestCase
{
    use FakesImisDirectory;
    use RefreshDatabase;

    private FieldOffice $office;   // Eastern Samar: Director II, PSED from SG 26

    private FieldOffice $other;

    private User $fo;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        try {
            (new ExcelReportService)->templatePath(ReportType::Moa);
        } catch (\RuntimeException) {
            $this->markTestSkipped('The MOA template is not in storage/app/templates.');
        }

        $this->office = FieldOffice::factory()->headedBy(HeadType::DirectorII)->create(['name' => 'Field Office-Eastern Samar', 'imis_code' => 'ESFO']);
        $this->other = FieldOffice::factory()->create(['name' => 'Field Office-Samar', 'imis_code' => 'SFO']);
        $this->fo = User::factory()->fieldOffice($this->office->id)->create();
        $this->admin = User::factory()->admin()->create();

        foreach (['Permanent', 'Casual', 'Contractual'] as $status) {
            ReferenceValue::create(['type' => 'employment_status', 'value' => $status]);
        }
        ReferenceValue::create(['type' => 'nature_of_appointment', 'value' => 'Original']);
    }

    // ---------------------------------------------------------------- helpers

    /** A filled-in MOA form: the blank form with the given rows written into the Plantilla / CasualCon sheets. */
    private function form(array $plantilla = [], array $casual = []): UploadedFile
    {
        $blank = app(AppointmentImporter::class)->blankForm();
        $book = IOFactory::load($blank['path']);
        @unlink($blank['path']);

        foreach (['Plantilla' => $plantilla, 'CasualCon' => $casual] as $name => $rows) {
            $sheet = $book->getSheetByName($name);
            foreach ($rows as $i => $row) {
                foreach ($row as $col => $value) {
                    $sheet->setCellValue($col.(10 + $i), $value);
                }
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'cares_test_').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);
        $book->disconnectWorksheets();

        return new UploadedFile($path, 'moa.xlsx', null, null, true);
    }

    /** A valid Plantilla row; override the columns a test cares about. */
    private function row(array $overrides = []): array
    {
        return $overrides + [
            'A' => 'Field Office-Eastern Samar', 'B' => 'DepEd Eastern Samar Division', 'C' => 'National Government Agency',
            'D' => '10/05/2026', 'E' => '10:30:00', 'F' => 'Ryan Jose M. Estoque', 'G' => '10/01/2026', 'H' => '10/01/2026',
            'I' => 'Reyes', 'J' => 'Ana', 'K' => 'N/A', 'L' => 'Cruz', 'M' => null, 'N' => 'X',
            'O' => 'Administrative Aide I', 'P' => 1, 'Q' => '1st', 'R' => 'Permanent', 'S' => 'Original',
            'T' => 'N/A', 'U' => 'N/A', 'V' => 'N/A', 'W' => 'No', 'X' => 'N/A', 'Y' => 'N/A', 'Z' => 'N/A', 'AA' => 'N/A', 'AB' => 'N/A',
            'AC' => 'No', 'AD' => 'No', 'AE' => 'No', 'AF' => 'N/A', 'AG' => 'N/A', 'AH' => 'N/A', 'AI' => 'N/A', 'AJ' => 'N/A', 'AK' => 'N/A',
        ];
    }

    private function preview(User $user, UploadedFile $file, ?string $office = null)
    {
        return $this->actingAs($user)->post('/appointments/import/preview', array_filter(['file' => $file, 'field_office_id' => $office], fn ($v) => $v !== null));
    }

    private function importAs(User $user, UploadedFile $file, ?string $office = null): \Illuminate\Testing\TestResponse
    {
        $token = $this->preview($user, $file, $office)->assertOk()->json('token');

        return $this->actingAs($user)->post('/appointments/import', ['token' => $token]);
    }

    // -------------------------------------------------------------- round trip

    public function test_a_moa_report_imports_back_exactly(): void
    {
        $make = fn (array $s) => Appointment::factory()->create($s + ['field_office_id' => $this->office->id, 'date_received' => '2026-10-05']);

        $make(['last_name' => 'Aaa', 'sex' => 'M', 'salary_grade' => 22, 'level' => '2nd', 'position' => 'School Principal IV', 'nature_of_appointment' => 'Promotion',
            'publication_start' => '2026-06-08', 'publication_end' => '2026-06-18', 'publication_mode' => 'CSC Bulletin of Vacant Positions',
            'eligibility_type' => 'RA 1080 (Teacher)', 'is_first_time_entrant' => true, 'eligibility_exam_date' => '05/30/1993', 'eligibility_exam_place' => 'Borongan, Eastern Samar',
            'eligibility_issued_date' => '2006-06-30', 'eligibility_issued_place' => 'Tacloban City, Leyte', 'eligibility_validity' => '2028-06-30',
            'is_pwd' => true, 'csc_action' => 'Approved', 'action_officer' => 'Rey Albert B. Uy', 'action_date' => '2026-10-12', 'release_date' => '2026-10-14']);
        $make(['last_name' => 'Bbb', 'sex' => 'F', 'name_extension' => 'Jr.', 'middle_name' => null, 'time_received' => '14:50:00', 'is_solo_parent' => true, 'is_indigenous' => true,
            'csc_action' => 'Pending', 'pending_reason' => 'Lacks a certified true copy of the eligibility.']);
        $make(['last_name' => 'Ccc', 'sex' => 'F', 'salary_grade' => 24, 'csc_action' => 'Disapproved', 'action_officer' => 'Rey Albert B. Uy', 'action_date' => '2026-10-20',
            'disapproval_reason' => 'Violation of Section 132, Rule XII, CSC MC No. 8, s. 2025.']);
        $make(['last_name' => 'Ddd', 'sex' => 'M', 'employment_status' => 'Casual', 'salary_grade' => 1, 'csc_action' => 'Validated', 'action_officer' => 'Ryan Jose M. Estoque', 'action_date' => '2026-10-15', 'release_date' => '2026-10-16']);
        $make(['last_name' => 'Eee', 'sex' => 'F', 'employment_status' => 'Contractual', 'salary_grade' => 3]);

        $fields = ['agency', 'sector', 'received_by', 'last_name', 'first_name', 'name_extension', 'middle_name', 'sex', 'position', 'salary_grade', 'level',
            'employment_status', 'nature_of_appointment', 'publication_mode', 'eligibility_type', 'is_first_time_entrant', 'eligibility_exam_date', 'eligibility_exam_place',
            'eligibility_issued_place', 'is_pwd', 'is_indigenous', 'is_solo_parent', 'csc_action', 'action_officer', 'pending_reason', 'disapproval_reason'];
        $dates = ['date_received', 'date_issued', 'date_effective', 'publication_start', 'publication_end', 'eligibility_issued_date', 'eligibility_validity', 'action_date', 'release_date'];
        $snapshot = fn (Appointment $a) => collect($fields)->mapWithKeys(fn ($f) => [$f => $a->$f])
            ->merge(collect($dates)->mapWithKeys(fn ($f) => [$f => $a->$f?->toDateString()]))
            ->put('time_received', $a->time_received ? substr($a->time_received, 0, 5) : null)
            ->put('processed_by', $a->processed_by->value)->all();

        $before = Appointment::orderBy('last_name')->get()->mapWithKeys(fn ($a) => [$a->last_name => $snapshot($a)]);

        // The report the office would send, then wipe the records and bring the report back in.
        $file = (new ExcelReportService)->generate(ReportType::Moa, $this->office, 2026, 10);
        Appointment::query()->delete();
        $upload = new UploadedFile($file['path'], $file['name'], null, null, true);

        $this->importAs($this->admin, $upload, (string) $this->office->id)->assertRedirect('/appointments');

        $after = Appointment::orderBy('last_name')->get()->mapWithKeys(fn ($a) => [$a->last_name => $snapshot($a)]);

        $this->assertSame(5, $after->count());
        foreach ($before as $name => $expected) {
            $this->assertEquals($expected, $after[$name], "{$name} came back different");
        }
        $this->assertSame($this->office->id, Appointment::where('last_name', 'Aaa')->value('field_office_id'));
    }

    // ----------------------------------------------------- the two-step process

    public function test_checking_a_file_saves_nothing_and_confirming_saves_the_good_rows(): void
    {
        $file = $this->form([$this->row(['I' => 'Good']), $this->row(['I' => 'Alsogood', 'J' => 'Ben', 'N' => null, 'M' => 'X'])]);

        $json = $this->preview($this->fo, $file)->assertOk()->json();

        $this->assertSame(0, Appointment::count(), 'a preview saves nothing');
        $this->assertSame(2, $json['counts']['rows']);
        $this->assertSame(2, $json['counts']['ready']);
        $this->assertCount(2, $json['sample']);

        $this->actingAs($this->fo)->post('/appointments/import', ['token' => $json['token']])
            ->assertRedirect('/appointments')
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'Imported 2 appointments from moa.xlsx'));

        $this->assertSame(2, Appointment::count());
        $this->assertSame([$this->office->id], Appointment::pluck('field_office_id')->unique()->all());
    }

    public function test_each_bad_row_is_reported_with_its_row_and_why_and_the_rest_still_import(): void
    {
        $file = $this->form([
            $this->row(['I' => 'Fine']),
            $this->row(['I' => 'Baddate', 'D' => 'someday']),
            $this->row(['I' => 'Nosex', 'N' => null]),
            $this->row(['I' => 'Badgrade', 'P' => 'abc']),
            $this->row(['I' => 'Badaction', 'AF' => 'Maybe']),
            $this->row(['I' => 'Noreason', 'AF' => 'Disapproved', 'AH' => '10/12/2026']),
        ]);

        $json = $this->preview($this->fo, $file)->assertOk()->json();
        $byName = collect($json['problems'])->keyBy('name');

        $this->assertSame(6, $json['counts']['rows']);
        $this->assertSame(1, $json['counts']['ready']);
        $this->assertSame(5, $json['counts']['invalid']);
        $this->assertSame(11, $byName['Baddate, Ana']['row']);
    }

    public function test_the_problem_list_gives_the_spreadsheet_row_and_a_readable_reason(): void
    {
        $file = $this->form([$this->row(['I' => 'Fine']), $this->row(['I' => 'Baddate', 'D' => 'someday']), $this->row(['I' => 'Nosex', 'N' => null])]);

        $problems = collect($this->preview($this->fo, $file)->assertOk()->json('problems'))->keyBy('name');

        $this->assertSame(11, $problems['Baddate, Ana']['row']);
        $this->assertSame('Plantilla', $problems['Baddate, Ana']['sheet']);
        $this->assertSame('error', $problems['Baddate, Ana']['kind']);
        $this->assertStringContainsString('date received', $problems['Baddate, Ana']['messages'][0]);
        $this->assertSame(12, $problems['Nosex, Ana']['row']);
        $this->assertStringContainsString('Mark the sex', $problems['Nosex, Ana']['messages'][0]);
        $this->assertArrayNotHasKey('Fine, Ana', $problems->all());
    }

    public function test_blank_rows_and_the_end_marker_are_ignored(): void
    {
        $file = $this->form([$this->row(['I' => 'One']), [], $this->row(['I' => 'Two', 'J' => 'Bea'])]);

        $this->assertSame(2, $this->preview($this->fo, $file)->json('counts.rows'));
    }

    // ------------------------------------------------------------- cell reading

    public function test_dates_times_and_text_are_read_the_way_the_forms_are_filled_in(): void
    {
        $serial = 46301; // 2026-10-06 as an Excel date
        $file = $this->form([$this->row([
            'I' => 'Mixed', 'D' => $serial, 'E' => 0.4375,              // an Excel date and an Excel time (10:30)
            'G' => '2026-10-01', 'H' => '1-Oct-26',                       // other typed formats
            'R' => 'permanent', 'S' => 'original', 'Q' => '2nd Level',   // the CSC's spelling and "2nd" restored
            'P' => 'SG 11', 'T' => '09/09/2026 to 09/24/2026', 'U' => 'N/A',
            'V' => 'CS Professional', 'W' => 'yes', 'X' => '03/17/2024', 'Z' => 45457, 'AC' => 'Yes',
        ])]);

        $this->importAs($this->fo, $file)->assertRedirect('/appointments');

        $a = Appointment::sole();
        $this->assertSame('2026-10-06', $a->date_received->toDateString());
        $this->assertSame('10:30', substr($a->time_received, 0, 5));
        $this->assertSame('2026-10-01', $a->date_issued->toDateString());
        $this->assertSame('2026-10-01', $a->date_effective->toDateString());
        $this->assertSame('Permanent', $a->employment_status);
        $this->assertSame('Original', $a->nature_of_appointment);
        $this->assertSame('2nd', $a->level);
        $this->assertSame(11, $a->salary_grade);
        $this->assertSame('2026-09-09', $a->publication_start->toDateString());
        $this->assertSame('2026-09-24', $a->publication_end->toDateString());
        $this->assertTrue($a->is_first_time_entrant);
        $this->assertTrue($a->is_pwd);
        $this->assertSame('03/17/2024', $a->eligibility_exam_date);
        $this->assertSame('2024-06-14', $a->eligibility_issued_date->toDateString());
        $this->assertNull($a->middle_name === 'N/A' ? 'N/A' : null, 'N/A is not stored as text');
        $this->assertNull($a->name_extension, 'N/A means nothing');
    }

    // ------------------------------------------------------------------ duplicates

    public function test_appointments_already_in_cares_are_not_imported_twice(): void
    {
        $file = $this->form([$this->row(['I' => 'Once'])]);
        $this->importAs($this->fo, $file)->assertRedirect('/appointments');
        $this->assertSame(1, Appointment::count());

        $again = $this->form([$this->row(['I' => 'Once']), $this->row(['I' => 'New', 'J' => 'Cora'])]);
        $json = $this->preview($this->fo, $again)->json();

        $this->assertSame(1, $json['counts']['duplicates']);
        $this->assertSame(1, $json['counts']['ready']);
        $this->assertStringContainsString('Already in CARES', collect($json['problems'])->firstWhere('name', 'Once, Ana')['messages'][0]);

        $this->actingAs($this->fo)->post('/appointments/import', ['token' => $json['token']]);
        $this->assertSame(2, Appointment::count());
    }

    public function test_a_row_repeated_inside_the_file_is_imported_once(): void
    {
        $file = $this->form([$this->row(['I' => 'Twice']), $this->row(['I' => 'Twice'])]);

        $json = $this->preview($this->fo, $file)->json();

        $this->assertSame(1, $json['counts']['duplicates']);
        $this->assertSame(1, $json['counts']['ready']);
        $this->assertStringContainsString('Repeated in this file (see row 10)', $json['problems'][0]['messages'][0]);
    }

    // ------------------------------------------------------------ offices and roles

    public function test_a_field_office_user_can_only_import_their_own_office(): void
    {
        $file = $this->form([
            $this->row(['I' => 'Mine']),
            $this->row(['I' => 'Elsewhere', 'A' => 'Field Office-Samar']),
            $this->row(['I' => 'Blankoffice', 'A' => null]),
        ]);

        $json = $this->preview($this->fo, $file)->json();
        $problems = collect($json['problems'])->keyBy('name');

        $this->assertSame(2, $json['counts']['ready'], 'their own, and one that names no office');
        $this->assertStringContainsString('You can only import appointments of Field Office-Eastern Samar', $problems['Elsewhere, Ana']['messages'][0]);
    }

    public function test_psed_can_read_the_office_from_each_row_by_name_or_imis_code(): void
    {
        $file = $this->form([
            $this->row(['I' => 'Byname', 'A' => 'Field Office-Eastern Samar']),
            $this->row(['I' => 'Bycode', 'A' => 'SFO']),
            $this->row(['I' => 'Unknown', 'A' => 'Field Office-Atlantis']),
            $this->row(['I' => 'Empty', 'A' => null]),
        ]);

        $json = $this->preview($this->admin, $file, 'file')->json();

        $this->assertSame(2, $json['counts']['ready']);
        $problems = collect($json['problems'])->keyBy('name');
        $this->assertStringContainsString('not a Field Office in CARES', $problems['Unknown, Ana']['messages'][0]);
        $this->assertStringContainsString('(column A) is empty', $problems['Empty, Ana']['messages'][0]);

        $this->actingAs($this->admin)->post('/appointments/import', ['token' => $json['token']]);
        $this->assertSame($this->office->id, Appointment::where('last_name', 'Byname')->value('field_office_id'));
        $this->assertSame($this->other->id, Appointment::where('last_name', 'Bycode')->value('field_office_id'));
    }

    public function test_choosing_an_office_puts_every_row_there_and_only_warns_when_the_file_names_another(): void
    {
        $file = $this->form([$this->row(['I' => 'One', 'A' => 'Field Office-Eastern Samar']), $this->row(['I' => 'Two', 'A' => 'Field Office-Samar'])]);

        $json = $this->preview($this->admin, $file, (string) $this->other->id)->json();

        $this->assertSame(2, $json['counts']['ready']);
        $this->assertSame('warning', collect($json['problems'])->firstWhere('name', 'One, Ana')['kind']);

        $this->actingAs($this->admin)->post('/appointments/import', ['token' => $json['token']]);
        $this->assertSame([$this->other->id], Appointment::pluck('field_office_id')->unique()->all());
    }

    public function test_a_field_office_cannot_record_the_action_on_appointments_psed_processes(): void
    {
        $act = ['AF' => 'Approved', 'AG' => 'Rey Uy', 'AH' => '10/12/2026', 'AI' => '10/13/2026'];
        $file = $this->form([
            $this->row(['I' => 'Mine', 'P' => 10] + $act),     // the Field Office processes SG 10
            $this->row(['I' => 'Psedowns', 'P' => 27] + $act), // a Director II office sends SG 27 to PSED
        ]);

        $json = $this->preview($this->fo, $file)->json();
        $this->assertStringContainsString('PSED processes this appointment', collect($json['problems'])->firstWhere('name', 'Psedowns, Ana')['messages'][0]);

        $this->actingAs($this->fo)->post('/appointments/import', ['token' => $json['token']]);

        $this->assertSame('Approved', Appointment::where('last_name', 'Mine')->value('csc_action'));
        $this->assertNull(Appointment::where('last_name', 'Psedowns')->value('csc_action'));
        $this->assertSame('psed', Appointment::where('last_name', 'Psedowns')->first()->processed_by->value, 'and it is routed on import');
    }

    public function test_psed_can_record_the_action_on_every_import(): void
    {
        $file = $this->form([$this->row(['I' => 'Psedowns', 'P' => 27, 'AF' => 'Approved', 'AG' => 'Rey Uy', 'AH' => '10/12/2026'])]);

        $this->importAs($this->admin, $file, (string) $this->office->id);

        $this->assertSame('Approved', Appointment::sole()->csc_action);
    }

    public function test_the_casual_sheet_is_read_and_a_wrong_status_is_pointed_out(): void
    {
        $casual = fn (array $o) => $o + [
            'A' => 'Field Office-Eastern Samar', 'B' => 'DepEd Eastern Samar Division', 'C' => 'National Government Agency', 'D' => '10/01/2026', 'E' => '08:00:00',
            'F' => 'Ryan', 'G' => '09/15/2026', 'H' => '09/15/2026', 'I' => 'Casualone', 'J' => 'Dan', 'K' => 'N/A', 'L' => 'Opriasa', 'M' => 'X',
            'O' => 'Administrative Aide I', 'P' => 1, 'Q' => '1st', 'R' => 'Casual', 'S' => 'Original',
            'T' => 'No', 'U' => 'No', 'V' => 'Yes', 'W' => 'Validated', 'X' => 'Ryan', 'Y' => '10/07/2026', 'Z' => '10/08/2026', 'AA' => 'N/A', 'AB' => 'N/A',
        ];

        $json = $this->preview($this->fo, $this->form([], [$casual([]), $casual(['I' => 'Wrongsheet', 'R' => 'Permanent'])]))->json();
        $this->assertSame(2, $json['counts']['casual']);
        $this->assertStringContainsString('will list it under Plantilla', collect($json['problems'])->firstWhere('name', 'Wrongsheet, Dan')['messages'][0]);

        $this->actingAs($this->fo)->post('/appointments/import', ['token' => $json['token']]);

        $a = Appointment::where('last_name', 'Casualone')->sole();
        $this->assertTrue($a->is_solo_parent, 'the casual sheet has its own column order');
        $this->assertFalse($a->is_pwd);
        $this->assertSame('Validated', $a->csc_action);
        $this->assertSame('2026-10-07', $a->action_date->toDateString());
        $this->assertNull($a->eligibility_type);
    }

    // ----------------------------------------------------------- the file itself

    public function test_a_file_that_is_not_the_moa_form_is_explained(): void
    {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $book->getActiveSheet()->setTitle('Sheet1')->setCellValue('A1', 'hello');
        $path = tempnam(sys_get_temp_dir(), 'cares_test_').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);

        $this->preview($this->fo, new UploadedFile($path, 'other.xlsx', null, null, true))
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'not the MOA form'));
    }

    public function test_an_empty_form_and_a_file_that_is_not_excel_are_refused(): void
    {
        $this->preview($this->fo, $this->form())->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'No appointments were found'));

        $this->preview($this->fo, UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'))->assertSessionHasErrors('file');
        $this->actingAs($this->fo)->postJson('/appointments/import/preview', [])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_psed_must_say_where_the_appointments_go(): void
    {
        $this->actingAs($this->admin)->postJson('/appointments/import/preview', ['file' => $this->form([$this->row()])])
            ->assertStatus(422)->assertJsonValidationErrors('field_office_id');
    }

    // ---------------------------------------------------------------- the token

    public function test_a_token_belongs_to_the_person_who_uploaded_the_file_and_works_once(): void
    {
        $token = $this->preview($this->fo, $this->form([$this->row(['I' => 'Mine'])]))->json('token');
        $stranger = User::factory()->fieldOffice($this->other->id)->create();

        $this->actingAs($stranger)->post('/appointments/import', ['token' => $token])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'expired'));
        $this->assertSame(0, Appointment::count());

        $this->actingAs($this->fo)->post('/appointments/import', ['token' => $token])->assertSessionHas('status');
        $this->assertSame(1, Appointment::count());

        $this->actingAs($this->fo)->post('/appointments/import', ['token' => $token])->assertSessionHas('error');
        $this->assertSame(1, Appointment::count(), 'the same check is never imported twice');
    }

    public function test_an_expired_or_made_up_token_imports_nothing(): void
    {
        $token = $this->preview($this->fo, $this->form([$this->row()]))->json('token');
        Cache::forget('appointment-import:'.$token);

        $this->actingAs($this->fo)->post('/appointments/import', ['token' => $token])->assertSessionHas('error');
        $this->actingAs($this->fo)->post('/appointments/import', ['token' => (string) \Illuminate\Support\Str::uuid()])->assertSessionHas('error');
        $this->actingAs($this->fo)->post('/appointments/import', ['token' => 'not-a-token'])->assertSessionHasErrors('token');
        $this->assertSame(0, Appointment::count());
    }

    public function test_the_stored_file_is_removed_after_the_import(): void
    {
        $token = $this->preview($this->fo, $this->form([$this->row()]))->json('token');
        $this->assertCount(1, Storage::disk('local')->files('imports'));

        $this->actingAs($this->fo)->post('/appointments/import', ['token' => $token]);

        $this->assertCount(0, Storage::disk('local')->files('imports'));
    }

    // ------------------------------------------------------------- audit and access

    public function test_the_import_is_recorded_in_the_audit_trail(): void
    {
        $this->importAs($this->fo, $this->form([$this->row(['I' => 'One']), $this->row(['I' => 'Bad', 'D' => 'x'])]));

        $log = AuditLog::where('action', 'appointment.import')->sole();
        $this->assertSame($this->fo->id, $log->user_id);
        $this->assertSame(1, $log->after['imported']);
        $this->assertSame(1, $log->after['rows_with_errors']);
        $this->assertSame('moa.xlsx', $log->after['file']);

        $entry = collect($this->actingAs($this->admin)->get('/audit-trail')->viewData('page')['props']['entries']['data'])->firstWhere('action', 'appointment.import');
        $this->assertSame('Imported appointments', $entry['action_label']);
        $this->assertSame('moa.xlsx · 1 imported', $entry['subject']);
    }

    public function test_the_blank_form_downloads_with_the_samples_cleared(): void
    {
        $response = $this->actingAs($this->fo)->get('/appointments/import/template')->assertOk();
        $response->assertDownload('CARES appointments import form.xlsx');

        $book = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $this->assertNull($book->getSheetByName('Plantilla')->getCell('I10')->getValue(), 'the template\'s sample people are gone');
        $this->assertNull($book->getSheetByName('CasualCon')->getCell('I10')->getValue());
        $this->assertStringContainsString('LAST', strtoupper((string) $book->getSheetByName('Plantilla')->getCell('I7')->getValue()), 'the headers stay');
    }

    public function test_guests_cannot_import(): void
    {
        $this->get('/appointments/import/template')->assertRedirect('/login');
        $this->post('/appointments/import/preview', [])->assertRedirect('/login');
        $this->post('/appointments/import', [])->assertRedirect('/login');
    }

    // ---------------------------------------------------------- the action officer

    public function test_a_written_action_officer_is_matched_to_the_imis_employee(): void
    {
        $this->fakeImisDirectory();
        $act = ['AF' => 'Approved', 'AH' => '10/12/2026', 'AI' => '10/13/2026', 'P' => 10];

        $json = $this->preview($this->admin, $this->form([
            $this->row(['I' => 'Matched', 'AG' => 'ryan jose estoque'] + $act),     // case, punctuation and the middle initial do not matter
            $this->row(['I' => 'Unknown', 'J' => 'Bea', 'AG' => 'Nobody Known'] + $act),
            $this->row(['I' => 'Twin', 'J' => 'Cy', 'AG' => 'Juan Dela Cruz'] + $act),  // two employees, in different offices, share this name (PSED sees both)
        ]), (string) $this->office->id)->assertOk()->json();

        $notes = collect($json['problems'])->keyBy('name');
        $this->assertArrayNotHasKey('Matched, Ana', $notes->all(), 'a matched officer needs no note');
        $this->assertStringContainsString('not (uniquely) among the IMIS employees', $notes['Unknown, Bea']['messages'][0]);
        $this->assertSame('warning', $notes['Twin, Cy']['kind']);
        $this->assertSame(3, $json['counts']['ready'], 'a note is not an error: all three import');

        $this->actingAs($this->admin)->post('/appointments/import', ['token' => $json['token']]);

        $matched = Appointment::where('last_name', 'Matched')->sole();
        $this->assertSame('Ryan Jose M. Estoque', $matched->action_officer, 'the name is IMIS\'s spelling');
        $this->assertSame(1, $matched->action_officer_imis_id);
        $this->assertSame('Nobody Known', Appointment::where('last_name', 'Unknown')->value('action_officer'));
        $this->assertNull(Appointment::where('last_name', 'Unknown')->value('action_officer_imis_id'));
        $this->assertNull(Appointment::where('last_name', 'Twin')->value('action_officer_imis_id'), 'an ambiguous name is not guessed');
    }

    public function test_action_officers_are_matched_only_within_the_users_jurisdiction(): void
    {
        $this->fakeImisDirectory();
        $act = ['AF' => 'Approved', 'AH' => '10/12/2026', 'AI' => '10/13/2026', 'P' => 10];
        $file = fn () => $this->form([
            $this->row(['I' => 'Own', 'AG' => 'Ryan Jose M. Estoque'] + $act),            // Eastern Samar
            $this->row(['I' => 'Samars', 'J' => 'Bea', 'AG' => 'Ana P. Reyes'] + $act),    // Samar
            $this->row(['I' => 'Psed', 'J' => 'Cy', 'AG' => 'Pedro P. Dizon'] + $act),     // PSED, a regional support unit
        ]);

        // A Field Office user: only their own office's employees are matched.
        $this->importAs($this->fo, $file())->assertRedirect('/appointments');
        $this->assertSame(1, Appointment::where('last_name', 'Own')->value('action_officer_imis_id'));
        $this->assertNull(Appointment::where('last_name', 'Samars')->value('action_officer_imis_id'));
        $this->assertSame('Ana P. Reyes', Appointment::where('last_name', 'Samars')->value('action_officer'), 'kept as typed');
        $this->assertNull(Appointment::where('last_name', 'Psed')->value('action_officer_imis_id'));

        // PSED: every Field Office's employees are matched, but never a regional support unit's.
        Appointment::query()->delete();
        $this->importAs($this->admin, $file(), (string) $this->office->id)->assertRedirect('/appointments');
        $this->assertSame(1, Appointment::where('last_name', 'Own')->value('action_officer_imis_id'));
        $this->assertSame(7, Appointment::where('last_name', 'Samars')->value('action_officer_imis_id'));
        $this->assertNull(Appointment::where('last_name', 'Psed')->value('action_officer_imis_id'));
    }

    public function test_a_concurrent_director_is_matched_for_the_office_they_hold(): void
    {
        $this->fakeImisDirectory();   // Rey Albert B. Uy works in Samar and holds Eastern Samar concurrently

        $this->importAs($this->fo, $this->form([
            $this->row(['I' => 'Signedbyrey', 'P' => 10, 'AF' => 'Approved', 'AG' => 'Rey Albert B. Uy', 'AH' => '10/12/2026']),
        ]))->assertRedirect('/appointments');

        $a = Appointment::sole();
        $this->assertSame(2, $a->action_officer_imis_id, 'an Eastern Samar user can name the Director II who holds the office');
        $this->assertSame('Rey Albert B. Uy', $a->action_officer);
    }

    public function test_the_action_officer_of_what_psed_processes_is_matched_to_a_regional_director(): void
    {
        $this->fakeImisDirectory();
        $act = ['AF' => 'Approved', 'AH' => '10/12/2026', 'AI' => '10/13/2026'];

        $json = $this->preview($this->admin, $this->form([
            $this->row(['I' => 'Psedmatch', 'P' => 27, 'AG' => 'alicia mendoza'] + $act),            // SG 27: PSED processes it, so a Director IV/III acts
            $this->row(['I' => 'Wrongpool', 'J' => 'Bea', 'P' => 27, 'AG' => 'Ryan Jose M. Estoque'] + $act),  // a Field Office employee cannot act on it
            $this->row(['I' => 'Fopool', 'J' => 'Cy', 'P' => 10, 'AG' => 'Alicia R. Mendoza'] + $act),          // SG 10: the Field Office processes it, so a director does not act
        ]), (string) $this->office->id)->assertOk()->json();

        $notes = collect($json['problems'])->keyBy('name');
        $this->assertArrayNotHasKey('Psedmatch, Ana', $notes->all());
        $this->assertSame('warning', $notes['Wrongpool, Bea']['kind']);
        $this->assertSame('warning', $notes['Fopool, Cy']['kind']);

        $this->actingAs($this->admin)->post('/appointments/import', ['token' => $json['token']]);

        $this->assertSame(8, Appointment::where('last_name', 'Psedmatch')->value('action_officer_imis_id'));
        $this->assertSame('Alicia R. Mendoza', Appointment::where('last_name', 'Psedmatch')->value('action_officer'));
        $this->assertNull(Appointment::where('last_name', 'Wrongpool')->value('action_officer_imis_id'));
        $this->assertNull(Appointment::where('last_name', 'Fopool')->value('action_officer_imis_id'));
    }

    public function test_when_imis_is_down_the_action_officer_is_kept_as_typed_and_the_import_goes_on(): void
    {
        $this->imisIsDown();

        $json = $this->preview($this->fo, $this->form([
            $this->row(['I' => 'Offline', 'P' => 10, 'AF' => 'Approved', 'AG' => 'Ryan Jose M. Estoque', 'AH' => '10/12/2026']),
        ]))->assertOk()->json();

        $this->assertSame(1, $json['counts']['ready']);
        $this->assertStringContainsString('IMIS could not be reached', $json['problems'][0]['messages'][0]);

        $this->actingAs($this->fo)->post('/appointments/import', ['token' => $json['token']]);
        $this->assertSame('Ryan Jose M. Estoque', Appointment::sole()->action_officer);
    }}
