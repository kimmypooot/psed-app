<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\FieldOffice;
use App\Models\User;
use App\Services\Reports\ReportPeriod;
use App\Services\SummaryDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * The Integrated CSE Applicants and Absorption Database Summary, filled in from appointments. Uses the real
 * workbook in storage/app/templates (skipped when it is not there, like the other reports).
 */
class SummaryDatabaseTest extends TestCase
{
    use RefreshDatabase;

    private const AGENCY = 'Municipal Government of Almeria, Biliran';   // the workbook says "..., Province of Biliran"

    private FieldOffice $office;

    protected function setUp(): void
    {
        parent::setUp();

        $this->office = FieldOffice::factory()->create(['name' => 'Field Office-Biliran', 'imis_code' => 'BFO']);
    }

    private function template(): void
    {
        try {
            (new SummaryDatabase)->templatePath();
        } catch (\RuntimeException) {
            $this->markTestSkipped('The Summary Database workbook is not in storage/app/templates.');
        }
    }

    private function make(array $state): void
    {
        Appointment::factory()->create($state + [
            'field_office_id' => $this->office->id, 'agency' => self::AGENCY, 'employment_status' => 'Permanent',
            'csc_action' => 'Approved', 'action_date' => '2026-03-10', 'is_first_time_entrant' => false,
        ]);
    }

    private function generate(ReportPeriod $period): Spreadsheet
    {
        $file = (new SummaryDatabase)->generate($period);
        $book = IOFactory::load($file['path']);
        @unlink($file['path']);

        return $book;
    }

    /** The row of the agency on a sheet, and the office's own row above it. */
    private function rows(Worksheet $sheet, string $contains = 'Almeria'): array
    {
        $office = $agency = null;
        for ($i = 6; $i <= $sheet->getHighestDataRow('B'); $i++) {
            $name = (string) $sheet->getCell("B{$i}")->getValue();
            if ($sheet->getCell("A{$i}")->getValue() === '-' && $name === 'Biliran') {
                $office = $i;
            }
            if ($office && str_contains($name, $contains)) {
                $agency = $i;
                break;
            }
        }

        return [$office, $agency];
    }

    private function cell(Spreadsheet $book, string $sheet, string $cell): int|float
    {
        return (float) $book->getSheetByName($sheet)->getCell($cell)->getCalculatedValue();
    }

    private function march(): ReportPeriod
    {
        return ReportPeriod::month(2026, 3);
    }

    // ------------------------------------------------------------------ the periods

    public function test_a_period_can_be_a_year_a_quarter_a_month_or_any_range(): void
    {
        $this->assertSame(['2026-01-01', '2026-12-31', '2026'], [ReportPeriod::year(2026)->from->toDateString(), ReportPeriod::year(2026)->to->toDateString(), ReportPeriod::year(2026)->label]);
        $this->assertSame(['2026-04-01', '2026-06-30', 'Q2 2026'], [ReportPeriod::quarter(2026, 2)->from->toDateString(), ReportPeriod::quarter(2026, 2)->to->toDateString(), ReportPeriod::quarter(2026, 2)->label]);
        $this->assertSame(['2026-02-01', '2026-02-28', 'February 2026'], [ReportPeriod::month(2026, 2)->from->toDateString(), ReportPeriod::month(2026, 2)->to->toDateString(), ReportPeriod::month(2026, 2)->label]);

        $range = ReportPeriod::custom(CarbonImmutable::parse('2026-03-15'), CarbonImmutable::parse('2026-04-20'));
        $this->assertSame(['2026-03-15', '2026-04-20'], [$range->from->toDateString(), $range->to->toDateString()]);

        $this->expectException(InvalidArgumentException::class);
        ReportPeriod::custom(CarbonImmutable::parse('2026-04-20'), CarbonImmutable::parse('2026-03-15'));
    }

    // ------------------------------------------------------------------ what goes in the workbook

    public function test_k12_graduates_are_counted_by_the_eligibility_they_used_and_their_status(): void
    {
        $this->template();
        $this->make(['is_k12_graduate' => true, 'is_first_time_entrant' => true, 'eligibility_type' => 'CSE-Preference Rating (Professional)']);   // CSE-PR, permanent
        $this->make(['is_k12_graduate' => true, 'is_first_time_entrant' => true, 'eligibility_type' => 'Sangguniang Kabataan Official Eligibility (SKOE)', 'skoe_position' => 'SK Official', 'employment_status' => 'Casual']);
        $this->make(['is_k12_graduate' => true, 'eligibility_type' => null, 'employment_status' => 'Casual']);   // other means, non-permanent
        // Not counted: not a K-12 graduate, not approved, or approved outside the period.
        $this->make(['is_k12_graduate' => false, 'is_first_time_entrant' => true, 'eligibility_type' => 'CS Professional']);
        $this->make(['is_k12_graduate' => true, 'csc_action' => 'Disapproved']);
        $this->make(['is_k12_graduate' => true, 'action_date' => '2026-04-02']);

        $book = $this->generate($this->march());
        [, $row] = $this->rows($book->getSheetByName('K-12 Absorption Summary'));

        $get = fn (string $col) => $this->cell($book, 'K-12 Absorption Summary', $col.$row);
        $this->assertSame([1.0, 1.0, 0.0, 0.0, 1.0], [$get('D'), $get('E'), $get('F'), $get('G'), $get('H')], 'CSE-PR, SKOE, professional, sub-professional, other means');
        $this->assertSame([2.0, 1.0, 3.0], [$get('I'), $get('J'), $get('K')], 'non-permanent, regular plantilla, and the total the template adds up');
    }

    public function test_the_skoe_and_special_group_sheets_are_filled_from_the_marks_on_the_appointment(): void
    {
        $this->template();
        $this->make(['is_first_time_entrant' => true, 'eligibility_type' => 'Sangguniang Kabataan Official Eligibility (SKOE)', 'skoe_position' => 'SK Secretary']);
        $this->make(['is_first_time_entrant' => true, 'eligibility_type' => 'Sangguniang Kabataan Official Eligibility (SKOE)', 'skoe_position' => 'SK Official', 'employment_status' => 'Casual']);   // not permanent
        $this->make(['is_pwd' => true, 'is_first_time_entrant' => true]);                          // PWD absorbed
        $this->make(['is_pwd' => true, 'employment_status' => 'Contractual']);                   // PWD, non-permanent
        $this->make(['is_indigenous' => true, 'employment_status' => 'Casual']);
        $this->make(['is_solo_parent' => true, 'is_first_time_entrant' => true]);

        $book = $this->generate($this->march());

        [, $skoe] = $this->rows($book->getSheetByName('SKOE Absorption Summary'));
        $this->assertSame(1.0, $this->cell($book, 'SKOE Absorption Summary', "H{$skoe}"), 'absorbed as permanent: the casual one is not');
        $this->assertSame(0.0, $this->cell($book, 'SKOE Absorption Summary', "D{$skoe}"), 'grantees are the SK position columns PSED types, not the appointees');

        [, $row] = $this->rows($book->getSheetByName('Special Groups Summary'));
        $get = fn (string $col) => $this->cell($book, 'Special Groups Summary', $col.$row);
        $this->assertSame([1.0, 1.0, 0.0, 1.0, 1.0, 0.0], [$get('D'), $get('E'), $get('H'), $get('I'), $get('L'), $get('M')], 'PWD absorbed / non-permanent, IP, solo parent');
        $this->assertSame(2.0, $get('O'), 'the template adds the three groups absorbed');
    }

    public function test_the_cells_the_appointments_cannot_fill_are_left_alone(): void
    {
        $this->template();
        $this->make(['is_k12_graduate' => true, 'is_first_time_entrant' => true, 'eligibility_type' => 'CS Professional']);

        $book = $this->generate($this->march());
        [, $row] = $this->rows($book->getSheetByName('K-12 Absorption Summary'));

        $this->assertNull($book->getSheetByName('K-12 Absorption Summary')->getCell("C{$row}")->getValue(), 'applicants: for PSED to type');
        [, $skoe] = $this->rows($book->getSheetByName('SKOE Absorption Summary'));
        $this->assertNull($book->getSheetByName('SKOE Absorption Summary')->getCell("E{$skoe}")->getValue(), 'grantees by SK position: for PSED to type');
        [, $jo] = $this->rows($book->getSheetByName('JOCOSC6 Summary'));
        $this->assertNull($book->getSheetByName('JOCOSC6 Summary')->getCell("C{$jo}")->getValue(), 'Job Order applicants: for PSED to type');
    }

    public function test_an_agency_that_is_not_on_the_workbook_is_counted_in_its_office_and_reported(): void
    {
        $this->template();
        $this->make(['is_k12_graduate' => true, 'is_first_time_entrant' => true, 'eligibility_type' => 'CS Professional', 'agency' => 'Bureau of Imaginary Affairs']);

        $report = (new SummaryDatabase)->check($this->march());
        $this->assertSame([['agency' => 'Bureau of Imaginary Affairs', 'appointments' => 1]], $report['unmatched']['Field Office-Biliran']);

        $book = $this->generate($this->march());
        [$office] = $this->rows($book->getSheetByName('K-12 Absorption Summary'));
        $this->assertSame(1.0, $this->cell($book, 'K-12 Absorption Summary', "F{$office}"), "its office's total still includes it");
        $this->assertSame(1.0, $this->cell($book, 'K-12 Absorption Summary', 'F5'), 'and so does the regional total');
    }

    public function test_the_period_decides_what_is_counted(): void
    {
        $this->template();
        $this->make(['is_pwd' => true, 'is_first_time_entrant' => true, 'action_date' => '2026-02-10']);
        $this->make(['is_pwd' => true, 'is_first_time_entrant' => true, 'action_date' => '2026-05-10']);

        $count = fn (ReportPeriod $p) => $this->cell($this->generate($p), 'Special Groups Summary', 'D5');

        $this->assertSame(2.0, $count(ReportPeriod::year(2026)));
        $this->assertSame(1.0, $count(ReportPeriod::quarter(2026, 1)));
        $this->assertSame(1.0, $count(ReportPeriod::month(2026, 5)));
        $this->assertSame(2.0, $count(ReportPeriod::custom(CarbonImmutable::parse('2026-02-10'), CarbonImmutable::parse('2026-05-10'))), 'both ends are included');
        $this->assertSame(0.0, $count(ReportPeriod::month(2026, 4)));
    }

    // ------------------------------------------------------------------ the page

    public function test_only_psed_administrators_open_it_and_the_workbook_downloads(): void
    {
        $this->template();
        $this->make(['is_pwd' => true, 'is_first_time_entrant' => true]);
        $fo = User::factory()->fieldOffice($this->office->id)->create();
        $admin = User::factory()->admin()->create();
        $query = 'kind=month&year=2026&month=3';

        foreach (['/reports/summary-database', "/reports/summary-database/check?{$query}", "/reports/summary-database/download?{$query}"] as $url) {
            $this->actingAs($fo)->get($url)->assertForbidden();
        }

        $this->actingAs($admin)->get('/reports/summary-database')->assertOk();

        $this->get("/reports/summary-database/check?{$query}")->assertOk()
            ->assertJsonPath('appointments', 1)->assertJsonPath('period', 'March 2026')->assertJsonStructure(['sheets', 'unmatched']);

        $response = $this->get("/reports/summary-database/download?{$query}")->assertOk();
        $this->assertStringContainsString('March 2026', (string) $response->headers->get('content-disposition'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.summary_database', 'user_id' => $admin->id]);
    }

    public function test_a_period_that_makes_no_sense_is_refused(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->getJson('/reports/summary-database/check?kind=custom&from=2026-05-01&to=2026-04-01')->assertStatus(422)->assertJsonValidationErrors('to');
        $this->getJson('/reports/summary-database/check?kind=quarter&year=2026&quarter=5')->assertStatus(422)->assertJsonValidationErrors('quarter');
        $this->getJson('/reports/summary-database/check?kind=week')->assertStatus(422)->assertJsonValidationErrors('kind');
    }
}
