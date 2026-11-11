<?php

namespace Tests\Feature;

use App\Enums\HeadType;
use App\Enums\ReportType;
use App\Models\Appointment;
use App\Models\FieldOffice;
use App\Services\ExcelReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Tests\TestCase;

/**
 * Hydrates the real CSC templates in storage/app/templates. Skipped when they
 * are not there (they are not part of the repository).
 */
class ExcelReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExcelReportService $service;

    private FieldOffice $office;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ExcelReportService;

        foreach (ReportType::cases() as $type) {
            try {
                $this->service->templatePath($type);
            } catch (\RuntimeException) {
                $this->markTestSkipped("{$type->label()} template is not in storage/app/templates.");
            }
        }

        $this->office = FieldOffice::factory()->headedBy(HeadType::DirectorII)->create([
            'name' => 'Field Office-Eastern Samar', 'imis_code' => 'ESFO', 'location' => 'Borongan City',
        ]);
    }

    /** Runs a report and loads the resulting file. */
    private function run_(ReportType $type, int $month = 10): Spreadsheet
    {
        $file = $this->service->generate($type, $this->office, 2026, $month);
        $spreadsheet = IOFactory::load($file['path']);
        @unlink($file['path']);

        return $spreadsheet;
    }

    private function make(int $count, array $state = []): void
    {
        Appointment::factory()->count($count)->state($state + ['field_office_id' => $this->office->id])->create();
    }

    public function test_file_names_follow_the_csc_naming(): void
    {
        $this->assertSame('ESFO IPMR - October 2026.xlsx', ReportType::Ipmr->downloadName($this->office, 2026, 10));
        $this->assertSame('ESFO MRAP - October 2026.xlsx', ReportType::Mrap->downloadName($this->office, 2026, 10));
        $this->assertSame('ESFO MOA - October 2026.xlsx', ReportType::Moa->downloadName($this->office, 2026, 10));
        $this->assertSame('AbsorptionRate_2026_10_Field-Office-Eastern-Samar.xlsx', ReportType::Absorption->downloadName($this->office, 2026, 10));
    }

    public function test_a_missing_template_is_reported_clearly(): void
    {
        config(['psed.templates.moa' => 'does-not-exist*.xlsx']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MOA template was not found');

        $this->service->generate(ReportType::Moa, $this->office, 2026, 10);
    }

    // ---------------------------------------------------------------- MOA

    public function test_moa_splits_plantilla_and_casual_and_keeps_the_template_intact(): void
    {
        $this->make(3, ['date_received' => '2026-10-05', 'employment_status' => 'Permanent']);
        $this->make(2, ['date_received' => '2026-10-06', 'employment_status' => 'Casual']);
        $this->make(1, ['date_received' => '2026-09-01']);   // neither received nor acted in October
        // Received in September but acted on in October: belongs in October's MOA.
        $this->make(1, ['date_received' => '2026-09-28', 'csc_action' => 'Approved', 'action_date' => '2026-10-03']);

        $moa = $this->run_(ReportType::Moa);
        $plantilla = $moa->getSheetByName('Plantilla');
        $casual = $moa->getSheetByName('CasualCon');

        $this->assertSame('OCTOBER, 2026', $plantilla->getCell('C4')->getValue());
        $this->assertSame('Field Office-Eastern Samar', $plantilla->getCell('A10')->getValue());
        $this->assertNotNull($plantilla->getCell('I13')->getValue(), '3 permanent + 1 acted = 4 rows (10-13)');
        $this->assertNull($plantilla->getCell('I14')->getValue());
        $this->assertNotNull($casual->getCell('I11')->getValue());
        $this->assertNull($casual->getCell('I12')->getValue());

        // The official look survived: footer still merged, validations, hidden RefData.
        $this->assertContains('A275:AK275', $plantilla->getMergeCells());
        $this->assertSame('— NOTHING FOLLOWS —', $plantilla->getCell('A275')->getValue());
        $this->assertCount(13, $plantilla->getDataValidationCollection());
        $this->assertSame('hidden', $moa->getSheetByName('RefData')->getSheetState());

        // Dates are real dates in the template's own format, on every written row.
        $this->assertSame('mm/dd/yyyy;@', $plantilla->getStyle('D13')->getNumberFormat()->getFormatCode());
        // Rows are ordered by date received: the September one (acted in October) first.
        $this->assertEqualsWithDelta(Date::PHPToExcel(new \DateTime('2026-09-28')), $plantilla->getCell('D10')->getValue(), 0.001);
        $this->assertEqualsWithDelta(Date::PHPToExcel(new \DateTime('2026-10-05')), $plantilla->getCell('D11')->getValue(), 0.001);
    }

    public function test_moa_inserts_rows_and_pushes_the_footer_down_when_data_outgrows_the_template(): void
    {
        $this->make(270, ['date_received' => '2026-10-05']);   // the sheet has room for 265

        $moa = $this->run_(ReportType::Moa);
        $sheet = $moa->getSheetByName('Plantilla');

        // 270 rows from row 10 -> last data row 279, footer moves from 275 to 280.
        $this->assertNotNull($sheet->getCell('I279')->getValue());
        $this->assertNull($sheet->getCell('I280')->getValue());
        $this->assertSame('— NOTHING FOLLOWS —', $sheet->getCell('A280')->getValue());
        $this->assertNotSame('— NOTHING FOLLOWS —', $sheet->getCell('A275')->getValue(), 'row 275 is now an ordinary data row');
        $this->assertContains('A280:AK280', $sheet->getMergeCells());
        $this->assertNotContains('A275:AK275', $sheet->getMergeCells());

        // The inserted rows look like the template's data rows.
        $this->assertSame('mm/dd/yyyy;@', $sheet->getStyle('D279')->getNumberFormat()->getFormatCode());
        $this->assertSame($sheet->getRowDimension(10)->getRowHeight(), $sheet->getRowDimension(279)->getRowHeight());
    }

    // ----------------------------------------------------------- Absorption

    public function test_absorption_lists_first_time_entrants_approved_in_the_month_with_the_breakdown(): void
    {
        $this->make(2, ['sex' => 'M', 'csc_action' => 'Approved', 'action_date' => '2026-10-10', 'eligibility_type' => 'CS Professional', 'is_first_time_entrant' => true]);
        $this->make(1, ['sex' => 'F', 'csc_action' => 'Validated', 'action_date' => '2026-10-11', 'eligibility_type' => 'CS Subprofessional', 'is_first_time_entrant' => true]);
        // Not counted: not first-time, disapproved, or acted in another month.
        $this->make(1, ['csc_action' => 'Approved', 'action_date' => '2026-10-12', 'is_first_time_entrant' => false]);
        $this->make(1, ['csc_action' => 'Disapproved', 'action_date' => '2026-10-12', 'is_first_time_entrant' => true]);
        $this->make(1, ['csc_action' => 'Approved', 'action_date' => '2026-09-12', 'is_first_time_entrant' => true]);

        $sheet = $this->run_(ReportType::Absorption)->getSheet(0);

        $this->assertSame('October', $sheet->getTitle());
        $this->assertSame('OCTOBER 2026', (string) $sheet->getCell('C2')->getValue());
        $this->assertSame(3, (int) $sheet->getCell('C4')->getValue());
        $this->assertSame(2, (int) $sheet->getCell('C5')->getValue());
        $this->assertSame(1, (int) $sheet->getCell('C6')->getValue());
        $this->assertSame('CS Professional', (string) $sheet->getCell('F3')->getValue());
        $this->assertSame(2, (int) $sheet->getCell('G3')->getValue());
        $this->assertSame(3, (int) $sheet->getCell('G10')->getValue());
        $this->assertSame('ESFO', (string) $sheet->getCell('M13')->getValue());
        $this->assertNotEmpty((string) $sheet->getCell('B15')->getValue());
        $this->assertEmpty((string) $sheet->getCell('B16')->getValue(), 'the template\'s sample rows are cleared');
    }

    public function test_absorption_pushes_the_signatures_down_when_there_are_more_rows_than_the_template_holds(): void
    {
        $this->make(30, ['csc_action' => 'Approved', 'action_date' => '2026-10-10', 'is_first_time_entrant' => true]);

        $sheet = $this->run_(ReportType::Absorption)->getSheet(0);

        // 30 rows: 13..42 (template room ends at 39) -> 3 rows inserted, footer 40 -> 43.
        $this->assertNotEmpty((string) $sheet->getCell('B42')->getValue());
        $this->assertSame('Prepared by:', (string) $sheet->getCell('B43')->getValue());
        $this->assertSame('JUAN DELA CRUZ', (string) $sheet->getCell('B47')->getValue());
        $this->assertSame('MARIA SANTOS', (string) $sheet->getCell('H47')->getValue());
    }

    // ----------------------------------------------------------- MRAP / IPMR

    public function test_mrap_fills_only_the_selected_months_column_and_leaves_formulas_alone(): void
    {
        $this->make(4, ['date_received' => '2026-10-05', 'eligibility_type' => 'CS Professional']);
        $this->make(2, ['date_received' => '2026-10-06']);                                     // no eligibility needed
        $this->make(1, ['date_received' => '2026-09-20']);                                     // backlog, unacted
        $this->make(3, ['date_received' => '2026-10-07', 'csc_action' => 'Approved', 'action_date' => '2026-10-15']);
        $this->make(1, ['date_received' => '2026-10-07', 'csc_action' => 'Disapproved', 'action_date' => '2026-10-16']);

        $sheet = $this->run_(ReportType::Mrap)->getSheet(0);

        $this->assertSame(10, (int) $sheet->getCell('N7')->getValue(), 'received in October: 4+2+3+1');
        $this->assertSame(4, (int) $sheet->getCell('N9')->getValue(), 'with eligibility');
        $this->assertSame(6, (int) $sheet->getCell('N10')->getValue(), 'without');
        $this->assertSame(1, (int) $sheet->getCell('N11')->getValue(), 'backlog from September');
        $this->assertSame(10, (int) $sheet->getCell('N12')->getValue());
        $this->assertSame(4, (int) $sheet->getCell('N15')->getValue(), 'acted in October');
        $this->assertSame(1, (int) $sheet->getCell('N19')->getValue(), 'backlog still unacted');
        $this->assertSame(6, (int) $sheet->getCell('N20')->getValue(), 'received in October, still unacted');
        $this->assertSame(1, (int) $sheet->getCell('N25')->getValue(), 'disapproved');

        $this->assertSame('=+N9+N10', $sheet->getCell('N8')->getValue(), 'template formulas are untouched');
        $this->assertSame('for the month of OCTOBER 2026', $sheet->getCell('A2')->getValue());
        $this->assertSame('Name of Field Office:  FIELD OFFICE-EASTERN SAMAR, Borongan City', $sheet->getCell('A3')->getValue());
        $this->assertSame('Date: 01 November 2026', $sheet->getCell('M40')->getValue());
    }

    public function test_ipmr_fills_the_selected_months_column_which_skips_the_semester_column(): void
    {
        $this->make(2, ['sex' => 'M', 'date_received' => '2026-10-05', 'csc_action' => 'Approved', 'action_date' => '2026-10-09', 'is_first_time_entrant' => true]);
        $this->make(1, ['sex' => 'F', 'date_received' => '2026-10-05', 'csc_action' => 'Approved', 'action_date' => '2026-10-09', 'is_first_time_entrant' => true]);
        $this->make(1, ['date_received' => '2025-12-20', 'csc_action' => 'Approved', 'action_date' => '2026-10-02']);  // 2025 carry-over

        $sheet = $this->run_(ReportType::Ipmr, 10)->getSheetByName('IPMR 2026');

        // October is column O (Jul = L, because K is the 1st-semester column).
        $this->assertSame(2, (int) $sheet->getCell('O27')->getValue());
        $this->assertSame(1, (int) $sheet->getCell('O28')->getValue());
        $this->assertSame(3, (int) $sheet->getCell('O33')->getValue());
        $this->assertSame(3, (int) $sheet->getCell('O37')->getValue(), 'acted, received in 2026');
        $this->assertSame(1, (int) $sheet->getCell('O43')->getValue(), 'acted, received before 2026');
        $this->assertSame('Accomplishment Report for October 2026', $sheet->getCell('A2')->getValue());
        $this->assertSame('=SUM(E27:J27)', $sheet->getCell('K27')->getValue());

        // January goes to E, and July to L.
        $this->assertSame(0, (int) $this->run_(ReportType::Ipmr, 1)->getSheetByName('IPMR 2026')->getCell('E27')->getValue());
    }

    // ---------------------------------------------------------- consolidated

    /** Runs the consolidated (all Field Offices) report. */
    private function consolidated(ReportType $type, int $month = 10): Spreadsheet
    {
        $file = $this->service->generate($type, null, 2026, $month);
        $spreadsheet = IOFactory::load($file['path']);
        @unlink($file['path']);

        return $spreadsheet;
    }

    private function otherOffice(): FieldOffice
    {
        return FieldOffice::factory()->create(['name' => 'Field Office-Samar', 'imis_code' => 'SFO']);
    }

    public function test_consolidated_file_names_use_the_regional_code(): void
    {
        $this->assertSame('RO8 MOA - October 2026.xlsx', ReportType::Moa->downloadName(null, 2026, 10));
        $this->assertSame('AbsorptionRate_2026_10_Regional-Office-VIII.xlsx', ReportType::Absorption->downloadName(null, 2026, 10));
    }

    public function test_consolidated_moa_lists_every_offices_appointments(): void
    {
        $other = $this->otherOffice();
        $this->make(2, ['date_received' => '2026-10-05']);
        Appointment::factory()->count(3)->create(['field_office_id' => $other->id, 'date_received' => '2026-10-06']);

        $sheet = $this->consolidated(ReportType::Moa)->getSheetByName('Plantilla');

        $this->assertSame('CSC Regional Office VIII (all Field Offices)', $sheet->getCell('C3')->getValue());
        $this->assertNotNull($sheet->getCell('I14')->getValue(), '2 + 3 rows: 10-14');
        $this->assertNull($sheet->getCell('I15')->getValue());
        $offices = collect(range(10, 14))->map(fn ($r) => $sheet->getCell("A{$r}")->getValue())->unique()->sort()->values()->all();
        $this->assertSame(['Field Office-Eastern Samar', 'Field Office-Samar'], $offices, 'each row names its own office');
    }

    public function test_consolidated_absorption_uses_each_rows_imis_code_and_leaves_the_signatures_blank(): void
    {
        $other = $this->otherOffice();
        $this->make(1, ['csc_action' => 'Approved', 'action_date' => '2026-10-10', 'is_first_time_entrant' => true, 'last_name' => 'Aaa']);
        Appointment::factory()->create(['field_office_id' => $other->id, 'csc_action' => 'Approved', 'action_date' => '2026-10-11', 'is_first_time_entrant' => true, 'last_name' => 'Bbb']);

        $sheet = $this->consolidated(ReportType::Absorption)->getSheet(0);

        $this->assertSame(2, (int) $sheet->getCell('C4')->getValue());
        $this->assertSame('ESFO', (string) $sheet->getCell('M13')->getValue());
        $this->assertSame('SFO', (string) $sheet->getCell('M14')->getValue());
        $this->assertSame('Prepared by:', (string) $sheet->getCell('B40')->getValue());
        $this->assertEmpty((string) $sheet->getCell('B44')->getValue(), 'the template\'s Eastern Samar signatory is removed');
        $this->assertEmpty((string) $sheet->getCell('H44')->getValue());
    }

    public function test_an_office_without_signatories_does_not_inherit_the_templates_names(): void
    {
        $bare = FieldOffice::factory()->create(['name' => 'Field Office-Samar', 'imis_code' => 'SFO', 'prepared_by_name' => null, 'approved_by_name' => null]);

        $file = $this->service->generate(ReportType::Absorption, $bare, 2026, 10);
        $sheet = IOFactory::load($file['path'])->getSheet(0);
        @unlink($file['path']);

        $this->assertEmpty((string) $sheet->getCell('B44')->getValue());
    }

    public function test_consolidated_mrap_adds_up_the_offices_and_fills_every_month_from_january(): void
    {
        $other = $this->otherOffice();
        $this->make(2, ['date_received' => '2026-01-12']);                                         // January, office A
        Appointment::factory()->create(['field_office_id' => $other->id, 'date_received' => '2026-01-14']);  // January, office B
        $this->make(1, ['date_received' => '2026-10-05']);
        Appointment::factory()->count(2)->create(['field_office_id' => $other->id, 'date_received' => '2026-10-06']);

        $sheet = $this->consolidated(ReportType::Mrap)->getSheet(0);

        $this->assertSame(3, (int) $sheet->getCell('E7')->getValue(), 'January, both offices');
        $this->assertSame(0, (int) $sheet->getCell('F7')->getValue(), 'February: the template\'s Eastern Samar figure is replaced');
        $this->assertSame(3, (int) $sheet->getCell('N7')->getValue(), 'October, both offices');
        $this->assertSame('Name of Field Office:  CSC REGIONAL OFFICE VIII (ALL FIELD OFFICES)', $sheet->getCell('A3')->getValue());
        $this->assertEmpty((string) $sheet->getCell('C37')->getValue());
    }

    public function test_consolidated_ipmr_adds_up_the_offices(): void
    {
        $other = $this->otherOffice();
        $this->make(1, ['sex' => 'M', 'date_received' => '2026-10-05', 'csc_action' => 'Approved', 'action_date' => '2026-10-09', 'is_first_time_entrant' => true]);
        Appointment::factory()->create(['field_office_id' => $other->id, 'sex' => 'F', 'date_received' => '2026-10-05', 'csc_action' => 'Approved', 'action_date' => '2026-10-09', 'is_first_time_entrant' => true]);

        $sheet = $this->consolidated(ReportType::Ipmr)->getSheetByName('IPMR 2026');

        $this->assertSame(1, (int) $sheet->getCell('O27')->getValue());
        $this->assertSame(1, (int) $sheet->getCell('O28')->getValue());
        $this->assertSame(2, (int) $sheet->getCell('O33')->getValue());
        $this->assertSame('Office: CSC REGIONAL OFFICE VIII (ALL FIELD OFFICES)', $sheet->getCell('A3')->getValue());
    }
}
