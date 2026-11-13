<?php

namespace Tests\Feature;

use App\Enums\HeadType;
use App\Enums\ReportType;
use App\Models\Appointment;
use App\Models\FieldOffice;
use App\Models\User;
use App\Services\ExcelReportService;
use App\Services\Reports\ReportContext;
use App\Services\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * The monthly reports also take a quarter, a year or a range of dates. The lists (MOA, Absorption) take any
 * range; the IPMR and MRAP have a column per month, so they fill each month of the period.
 */
class ReportPeriodsTest extends TestCase
{
    use RefreshDatabase;

    private FieldOffice $office;

    private User $psed;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (ReportType::cases() as $type) {
            try {
                (new ExcelReportService)->templatePath($type);
            } catch (\RuntimeException) {
                $this->markTestSkipped("{$type->label()} template is not in storage/app/templates.");
            }
        }

        $this->office = FieldOffice::factory()->headedBy(HeadType::DirectorII)->create(['name' => 'Field Office-Eastern Samar', 'imis_code' => 'ESFO']);
        $this->psed = User::factory()->admin()->create();
    }

    private function receive(string $date, array $state = []): void
    {
        Appointment::factory()->create($state + ['field_office_id' => $this->office->id, 'date_received' => $date]);
    }

    private function open(ReportType $type, ?FieldOffice $office, ReportPeriod $period): Spreadsheet
    {
        $file = (new ExcelReportService)->generateFor($type, $office, $period);
        $book = IOFactory::load($file['path']);
        @unlink($file['path']);

        return $book;
    }

    // ------------------------------------------------------------------ which months an IPMR / MRAP fills

    public function test_the_month_columns_a_period_fills(): void
    {
        $office = fn (ReportPeriod $p) => ReportContext::forPeriod($this->office, $p)->monthsToFill();
        $region = fn (ReportPeriod $p) => ReportContext::forPeriod(null, $p)->monthsToFill();

        $this->assertSame([4, 5, 6], $office(ReportPeriod::quarter(2026, 2)));
        $this->assertSame(range(1, 12), $office(ReportPeriod::year(2026)));
        $this->assertSame([10], $office(ReportPeriod::month(2026, 10)));
        $this->assertSame(range(1, 6), $region(ReportPeriod::quarter(2026, 2)), 'the region fills from January, as its monthly report does');
        $this->assertSame([3, 4], $office(ReportPeriod::custom(CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-04-30'))));
        $this->assertSame([10], (new ReportContext($this->office, 2026, 10))->monthsToFill(), 'a month is as it was');
    }

    public function test_an_ipmr_for_a_quarter_fills_a_column_for_each_of_its_months(): void
    {
        $this->receive('2026-04-06');
        $this->receive('2026-05-04');
        $this->receive('2026-05-05');
        $this->receive('2026-06-02');
        $this->receive('2026-07-01');   // not in the quarter

        $sheet = $this->open(ReportType::Ipmr, $this->office, ReportPeriod::quarter(2026, 2))->getSheetByName('IPMR 2026');

        $this->assertSame([1, 2, 1], [(int) $sheet->getCell('H33')->getValue(), (int) $sheet->getCell('I33')->getValue(), (int) $sheet->getCell('J33')->getValue()], 'April, May and June (Jun is column J)');        $template = IOFactory::load((new ExcelReportService)->templatePath(ReportType::Ipmr))->getSheetByName('IPMR 2026');        $this->assertSame($template->getCell('L33')->getValue(), $sheet->getCell('L33')->getValue(), 'July is not in the quarter: left as the template has it');
        $template = IOFactory::load((new ExcelReportService)->templatePath(ReportType::Ipmr))->getSheetByName('IPMR 2026');
        $this->assertSame($template->getCell('L33')->getValue(), $sheet->getCell('L33')->getValue(), 'July is not in the quarter: left as the template has it');
        $this->assertSame('Accomplishment Report for Q2 2026', $sheet->getCell('A2')->getValue());
    }

    public function test_an_mrap_for_a_year_is_dated_the_day_after_it_ends(): void
    {
        $this->receive('2026-02-03');

        $sheet = $this->open(ReportType::Mrap, $this->office, ReportPeriod::year(2026))->getSheet(0);

        $this->assertSame(1, (int) $sheet->getCell('F7')->getValue(), 'February');
        $this->assertSame('for the period of 2026', $sheet->getCell('A2')->getValue());
        $this->assertSame('Date: 01 January 2027', $sheet->getCell('M40')->getValue());
    }

    // ------------------------------------------------------------------ the lists take any range

    public function test_a_moa_lists_what_was_received_or_acted_upon_in_any_range_of_dates(): void
    {
        $this->receive('2026-02-12', ['last_name' => 'Inside']);
        $this->receive('2026-03-18', ['last_name' => 'Alsoinside']);
        $this->receive('2026-01-05', ['last_name' => 'Outside']);
        $this->receive('2026-04-01', ['last_name' => 'Lateroutside']);
        $this->receive('2026-01-20', ['last_name' => 'Actedinside', 'csc_action' => 'Approved', 'action_date' => '2026-02-20']);

        $book = $this->open(ReportType::Moa, $this->office, ReportPeriod::custom(CarbonImmutable::parse('2026-02-10'), CarbonImmutable::parse('2026-03-20')));
        $names = collect($book->getSheetByName('Plantilla')->rangeToArray('I10:I60', null, false, false, false))->flatten()->filter()->values()->all();

        $this->assertEqualsCanonicalizing(['Inside', 'Alsoinside', 'Actedinside'], $names);
        $this->assertSame('FEB 10, 2026 TO MAR 20, 2026', strtoupper((string) $book->getSheetByName('Plantilla')->getCell('C4')->getValue()));
    }

    public function test_an_absorption_report_for_a_range_has_a_sheet_name_excel_accepts(): void
    {
        $this->receive('2026-02-12', ['is_first_time_entrant' => true, 'csc_action' => 'Approved', 'action_date' => '2026-02-20', 'eligibility_type' => 'CS Professional']);

        $book = $this->open(ReportType::Absorption, $this->office, ReportPeriod::custom(CarbonImmutable::parse('2026-02-10'), CarbonImmutable::parse('2026-03-20')));

        $title = $book->getSheet(0)->getTitle();
        $this->assertLessThanOrEqual(31, mb_strlen($title));
        $this->assertDoesNotMatchRegularExpression('/[:\\\\\/?*\[\]]/', $title);
        $this->assertSame('FEB 10, 2026 TO MAR 20, 2026', $book->getSheet(0)->getCell('C2')->getValue());
    }

    // ------------------------------------------------------------------ the page's downloads

    public function test_the_download_takes_a_year_a_quarter_or_a_range_and_names_the_file_after_it(): void
    {
        $this->receive('2026-05-04');
        $get = fn (string $query) => $this->actingAs($this->psed)->get("/reports/download?field_office_id={$this->office->id}&{$query}");

        $this->assertStringContainsString('ESFO IPMR - Q2 2026.xlsx', (string) $get('type=ipmr&kind=quarter&year=2026&quarter=2')->assertOk()->headers->get('content-disposition'));
        $this->assertStringContainsString('ESFO MRAP - 2026.xlsx', (string) $get('type=mrap&kind=year&year=2026')->assertOk()->headers->get('content-disposition'));
        $this->assertStringContainsString('ESFO MOA - Feb 10 2026 to Mar 20 2026.xlsx', (string) $get('type=moa&kind=custom&from=2026-02-10&to=2026-03-20')->assertOk()->headers->get('content-disposition'));
        $this->assertStringContainsString('ESFO MOA - October 2026.xlsx', (string) $get('type=moa&month=10&year=2026')->assertOk()->headers->get('content-disposition'), 'a month and a year alone still mean a month');
    }

    public function test_the_ipmr_and_mrap_ask_for_whole_months_where_a_range_would_cut_one_in_two(): void
    {
        $get = fn (string $query) => $this->actingAs($this->psed)->get("/reports/download?field_office_id={$this->office->id}&{$query}");

        $get('type=ipmr&kind=custom&from=2026-02-10&to=2026-03-20')->assertRedirect('/reports')->assertSessionHas('error', fn ($m) => str_contains($m, 'whole months'));
        $get('type=mrap&kind=custom&from=2026-01-01&to=2027-03-31')->assertRedirect('/reports')->assertSessionHas('error');

        $get('type=ipmr&kind=custom&from=2026-02-01&to=2026-03-31')->assertOk();
    }

    public function test_the_data_check_covers_the_period_chosen(): void
    {
        $this->receive('2026-05-04', ['last_name' => 'Inquarter']);
        $this->receive('2026-08-04', ['last_name' => 'Outside']);

        $this->actingAs($this->psed)->getJson("/reports/check?field_office_id={$this->office->id}&kind=quarter&year=2026&quarter=2")
            ->assertOk()->assertJsonPath('checked', 1)->assertJsonPath('period', 'Q2 2026');

        $this->getJson("/reports/check?field_office_id={$this->office->id}&month=8&year=2026")->assertOk()->assertJsonPath('checked', 1)->assertJsonPath('period', 'August 2026');
        $this->getJson("/reports/check?field_office_id={$this->office->id}&kind=custom&from=2026-06-01&to=2026-05-01")->assertStatus(422)->assertJsonValidationErrors('to');
    }
}
