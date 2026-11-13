<?php

namespace Tests\Feature;

use App\Enums\HeadType;
use App\Enums\ReportType;
use App\Models\Appointment;
use App\Models\FieldOffice;
use App\Models\OpeningBalance;
use App\Models\User;
use App\Services\ExcelReportService;
use App\Services\OpeningBalances;
use App\Services\Reports\MonthlyFigures;
use App\Services\Reports\ReportContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * The balance a Field Office carries into a year, set by PSED (the consolidator): how it is stored, who may set it,
 * and how it feeds the MRAP's Beginning Balance and section G.
 */
class OpeningBalanceTest extends TestCase
{
    use RefreshDatabase;

    private FieldOffice $office;

    private FieldOffice $other;

    private User $psed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->office = FieldOffice::factory()->headedBy(HeadType::DirectorII)->create(['name' => 'Field Office-Eastern Samar', 'imis_code' => 'ESFO', 'location' => 'Borongan City']);
        $this->other = FieldOffice::factory()->headedBy(HeadType::Standard)->create(['name' => 'Field Office-Biliran', 'imis_code' => 'BFO']);
        $this->psed = User::factory()->admin()->create();
    }

    private function appointments(FieldOffice $office, int $count, array $state = []): void
    {
        Appointment::factory()->count($count)->state($state + ['field_office_id' => $office->id])->create();
    }

    // ------------------------------------------------------------------ the balance itself

    public function test_without_a_set_balance_the_system_counts_what_was_unacted_on_31_december(): void
    {
        $this->appointments($this->office, 3, ['date_received' => '2025-11-20']);                                                          // unacted: counts
        $this->appointments($this->office, 1, ['date_received' => '2025-12-02', 'csc_action' => 'Approved', 'action_date' => '2026-02-10']); // acted after 31 Dec: still counts
        $this->appointments($this->office, 2, ['date_received' => '2025-10-02', 'csc_action' => 'Approved', 'action_date' => '2025-12-20']); // acted before: does not
        $this->appointments($this->office, 5, ['date_received' => '2026-03-02']);                                                          // this year: not carried

        $this->assertSame(['value' => 4, 'source' => 'system'], app(OpeningBalances::class)->for($this->office, 2026));
        $this->assertSame(['value' => 0, 'source' => 'system'], app(OpeningBalances::class)->for($this->other, 2026));
    }

    public function test_a_balance_set_by_psed_replaces_the_system_count_and_clearing_it_brings_the_count_back(): void
    {
        $this->appointments($this->office, 4, ['date_received' => '2025-11-20']);
        $service = app(OpeningBalances::class);

        $service->set($this->office, 2026, 25, 'Per the FO December report', $this->psed);
        $this->assertSame(['value' => 25, 'source' => 'psed'], $service->for($this->office, 2026));
        $this->assertSame(['value' => 4, 'source' => 'system'], $service->for($this->office, 2027), 'another year is unaffected');

        $service->set($this->office, 2026, null, null, $this->psed);
        $this->assertSame(['value' => 4, 'source' => 'system'], $service->for($this->office, 2026));
        $this->assertSame(0, OpeningBalance::count());

        $this->assertDatabaseHas('audit_logs', ['action' => 'opening_balance.set', 'user_id' => null]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'opening_balance.clear']);
    }

    // ------------------------------------------------------------------ feeding the reports

    public function test_a_set_balance_is_worked_down_by_what_is_acted_from_it_never_below_zero(): void
    {
        OpeningBalance::create(['field_office_id' => $this->office->id, 'year' => 2026, 'unacted' => 12]);
        // Carried in from 2025 and acted: 2 in March, 3 in October.
        $this->appointments($this->office, 2, ['date_received' => '2025-11-20', 'csc_action' => 'Approved', 'action_date' => '2026-03-05']);
        $this->appointments($this->office, 3, ['date_received' => '2025-11-21', 'csc_action' => 'Approved', 'action_date' => '2026-10-05']);
        $this->appointments($this->office, 1, ['date_received' => '2026-09-20']);   // received this year, unacted

        $f = MonthlyFigures::compute(new ReportContext($this->office, 2026, 10), [1, 3, 4, 10]);

        $this->assertSame(12, $f[1]['balance']);
        $this->assertSame([12, 0, 12, 12], [$f[1]['backlog_start'], $f[1]['backlog_acted'], $f[1]['backlog_end'], $f[1]['opening_unacted']], 'January: nothing acted yet');
        $this->assertSame([12, 2, 10], [$f[3]['backlog_start'], $f[3]['backlog_acted'], $f[3]['backlog_end']], 'March works 2 off');
        $this->assertSame(10, $f[4]['backlog_start']);
        $this->assertSame([10, 3, 7], [$f[10]['backlog_start'], $f[10]['backlog_acted'], $f[10]['backlog_end']], 'October: 12 - 2 = 10, 3 acted, 7 left');
        $this->assertSame(10 + 1, $f[10]['opening_unacted'], 'C.3: the carried backlog plus the one received in September');
        $this->assertSame(7 + 1, $f[10]['closing_unacted_prior'], 'E.2: what is left of both');
    }

    public function test_acting_on_more_than_the_balance_stops_at_zero(): void
    {
        OpeningBalance::create(['field_office_id' => $this->office->id, 'year' => 2026, 'unacted' => 2]);
        $this->appointments($this->office, 5, ['date_received' => '2025-11-20', 'csc_action' => 'Approved', 'action_date' => '2026-05-05']);

        $f = MonthlyFigures::compute(new ReportContext($this->office, 2026, 6), [5, 6]);

        $this->assertSame([2, 2, 0], [$f[5]['backlog_start'], $f[5]['backlog_acted'], $f[5]['backlog_end']]);
        $this->assertSame([0, 0, 0], [$f[6]['backlog_start'], $f[6]['backlog_acted'], $f[6]['backlog_end']]);
    }

    public function test_the_consolidated_balance_is_the_sum_of_the_offices_each_clamped_on_its_own(): void
    {
        OpeningBalance::create(['field_office_id' => $this->office->id, 'year' => 2026, 'unacted' => 2]);
        OpeningBalance::create(['field_office_id' => $this->other->id, 'year' => 2026, 'unacted' => 7]);
        // The first office acts on 3 carried appointments (more than its 2): it is 0, not -1, and does not use up the other's.
        $this->appointments($this->office, 3, ['date_received' => '2025-11-20', 'csc_action' => 'Approved', 'action_date' => '2026-04-05']);

        $f = MonthlyFigures::compute(new ReportContext(null, 2026, 5), [4, 5]);

        $this->assertSame(9, $f[4]['balance']);
        $this->assertSame([9, 2, 7], [$f[4]['backlog_start'], $f[4]['backlog_acted'], $f[4]['backlog_end']]);
        $this->assertSame(7, $f[5]['backlog_start']);
    }

    public function test_an_office_with_a_balance_but_no_appointments_yet_still_carries_it(): void
    {
        OpeningBalance::create(['field_office_id' => $this->other->id, 'year' => 2027, 'unacted' => 6]);

        $f = MonthlyFigures::compute(new ReportContext(null, 2027, 1), [1]);
        $this->assertSame(6, $f[1]['balance']);
        $this->assertSame(0, MonthlyFigures::compute(new ReportContext($this->office, 2027, 1), [1])[1]['balance'], 'another office is not given it');
        $this->assertSame(6, MonthlyFigures::compute(new ReportContext($this->other, 2027, 1), [1])[1]['balance']);
    }

    // ------------------------------------------------------------------ the MRAP itself

    private function mrap(int $year, int $month, ?FieldOffice $office = null): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $service = new ExcelReportService;

        try {
            $service->templatePath(ReportType::Mrap);
        } catch (\RuntimeException) {
            $this->markTestSkipped('The MRAP template is not in storage/app/templates.');
        }

        $file = $service->generate(ReportType::Mrap, $office, $year, $month);
        $book = IOFactory::load($file['path']);
        @unlink($file['path']);

        return $book;
    }

    public function test_the_mrap_shows_the_balance_in_the_beginning_balance_and_section_g(): void
    {
        OpeningBalance::create(['field_office_id' => $this->office->id, 'year' => 2026, 'unacted' => 12]);
        $this->appointments($this->office, 2, ['date_received' => '2025-11-20', 'csc_action' => 'Approved', 'action_date' => '2026-03-05']);
        $this->appointments($this->office, 3, ['date_received' => '2025-11-21', 'csc_action' => 'Approved', 'action_date' => '2026-10-05']);
        $this->appointments($this->office, 1, ['date_received' => '2026-09-20']);

        $october = $this->mrap(2026, 10, $this->office)->getSheet(0);
        $this->assertSame(12, (int) $october->getCell('N28')->getValue(), 'G.1');
        $this->assertSame(10, (int) $october->getCell('N29')->getValue(), 'G.2');
        $this->assertSame(3, (int) $october->getCell('N30')->getValue(), 'G.3');
        $this->assertSame(7, (int) $october->getCell('N31')->getValue(), 'G.4');
        $this->assertSame(11, (int) $october->getCell('N11')->getValue(), 'C.3');
        $this->assertSame(8, (int) $october->getCell('N19')->getValue(), 'E.2');

        $january = $this->mrap(2026, 1, $this->office)->getSheet(0);
        $this->assertSame(12, (int) $january->getCell('E6')->getValue(), 'the year starts from the balance');
        $this->assertSame(12, (int) $january->getCell('E28')->getValue());
        $this->assertSame('=+E28-E30', $january->getCell('E31')->getValue(), 'the template formula stays');
    }

    public function test_the_consolidated_mrap_starts_from_the_offices_balances_added_together(): void
    {
        OpeningBalance::create(['field_office_id' => $this->office->id, 'year' => 2026, 'unacted' => 5]);
        OpeningBalance::create(['field_office_id' => $this->other->id, 'year' => 2026, 'unacted' => 7]);

        $sheet = $this->mrap(2026, 3)->getSheet(0);

        $this->assertSame(12, (int) $sheet->getCell('E6')->getValue());
        $this->assertSame(12, (int) $sheet->getCell('G28')->getValue());
    }

    public function test_section_g_names_the_year_before_the_one_reported(): void
    {
        $sheet = $this->mrap(2027, 1, $this->office)->getSheet(0);

        $this->assertStringContainsString('December 31, 2026', (string) $sheet->getCell('B27')->getValue());
        $this->assertStringContainsString('December 31, 2026', (string) $sheet->getCell('D28')->getValue());
    }

    // ------------------------------------------------------------------ PSED's page

    public function test_psed_sets_the_balances_and_sees_what_the_system_counts(): void
    {
        $this->appointments($this->office, 4, ['date_received' => '2025-11-20']);

        $this->actingAs($this->psed)->get('/settings/opening-balances?year=2026')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Settings/OpeningBalances')->where('year', 2026)->has('offices', 2)
            ->where('offices.0.name', 'Field Office-Biliran')->where('offices.0.system', 0)
            ->where('offices.1.name', 'Field Office-Eastern Samar')->where('offices.1.system', 4)->where('offices.1.unacted', null));

        $this->put('/settings/opening-balances', ['year' => 2026, 'balances' => [
            ['field_office_id' => $this->office->id, 'unacted' => '30', 'note' => 'Per the December report'],
            ['field_office_id' => $this->other->id, 'unacted' => '', 'note' => ''],
        ]])->assertRedirect()->assertSessionHas('status');

        $this->assertDatabaseHas('opening_balances', ['field_office_id' => $this->office->id, 'year' => 2026, 'unacted' => 30, 'updated_by' => $this->psed->id, 'note' => 'Per the December report']);
        $this->assertDatabaseMissing('opening_balances', ['field_office_id' => $this->other->id]);

        $this->get('/settings/opening-balances?year=2026')->assertInertia(fn (Assert $page) => $page
            ->where('offices.1.unacted', 30)->where('offices.1.set_by', $this->psed->name));
    }

    public function test_in_the_launch_year_there_is_no_system_figure_only_what_psed_enters(): void
    {
        // Nothing was received before 2027: the system has nothing to count, so it suggests nothing.
        $this->appointments($this->office, 3, ['date_received' => '2027-02-02']);

        $this->actingAs($this->psed)->get('/settings/opening-balances?year=2027')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('has_system_data', false)->where('offices.0.system', null)->where('offices.1.system', null)->where('offices.1.unacted', null));

        $this->put('/settings/opening-balances', ['year' => 2027, 'balances' => [
            ['field_office_id' => $this->office->id, 'unacted' => '18'],
        ]]);

        $f = MonthlyFigures::compute(new ReportContext($this->office, 2027, 1), [1]);
        $this->assertSame([18, 18], [$f[1]['balance'], $f[1]['backlog_start']], 'the entered balance is where 2027 starts');

        // The next year the system holds 2027's appointments and can offer its own count.
        $this->travelTo('2027-12-01');
        $this->flushSession()->actingAs($this->psed)->get('/settings/opening-balances?year=2028')->assertInertia(fn (Assert $page) => $page
            ->where('has_system_data', true)->where('offices.1.system', 3));
    }

    public function test_a_balance_must_be_a_whole_number_of_zero_or_more(): void
    {
        foreach (['-1', '2.5', 'abc'] as $bad) {
            $this->actingAs($this->psed)->put('/settings/opening-balances', ['year' => 2026, 'balances' => [
                ['field_office_id' => $this->office->id, 'unacted' => $bad],
            ]])->assertSessionHasErrors('balances.0.unacted');
        }

        $this->assertSame(0, OpeningBalance::count());
    }

    public function test_only_psed_administrators_may_see_or_set_balances(): void
    {
        $fieldOffice = User::factory()->fieldOffice($this->office->id)->create();

        $this->actingAs($fieldOffice)->get('/settings/opening-balances')->assertForbidden();
        $this->put('/settings/opening-balances', ['year' => 2026, 'balances' => [['field_office_id' => $this->office->id, 'unacted' => 5]]])->assertForbidden();
        $this->assertSame(0, OpeningBalance::count());
    }
}
