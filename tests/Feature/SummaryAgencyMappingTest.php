<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\FieldOffice;
use App\Models\SummaryAgencyAlias;
use App\Models\User;
use App\Services\Reports\ReportPeriod;
use App\Services\SummaryDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * Pairing a CARES agency with a row of the Summary Database workbook when the names differ, or saying it has none.
 * Uses the real workbook in storage/app/templates (skipped when it is not there).
 */
class SummaryAgencyMappingTest extends TestCase
{
    use RefreshDatabase;

    private const ODD = 'Bureau of Imaginary Affairs';   // matches no row of the workbook

    private FieldOffice $office;

    private User $psed;

    /** @var list<string> the workbook's agencies under this Field Office */
    private array $rows;

    protected function setUp(): void
    {
        parent::setUp();

        $this->office = FieldOffice::factory()->create(['name' => 'Field Office-Biliran', 'imis_code' => 'BFO']);
        $this->psed = User::factory()->admin()->create();

        try {
            $this->rows = collect((new SummaryDatabase)->review())->firstWhere('name', 'Field Office-Biliran')['rows'];
        } catch (\RuntimeException) {
            $this->markTestSkipped('The Summary Database workbook is not in storage/app/templates.');
        }
    }

    private function hire(string $agency): void
    {
        Appointment::factory()->create([
            'field_office_id' => $this->office->id, 'agency' => $agency, 'employment_status' => 'Permanent', 'is_k12_graduate' => true,
            'is_first_time_entrant' => true, 'eligibility_type' => 'CS Professional', 'csc_action' => 'Approved', 'action_date' => '2026-03-10',
        ]);
    }

    private function generate(): Spreadsheet
    {
        $file = (new SummaryDatabase)->generate(ReportPeriod::month(2026, 3));
        $book = IOFactory::load($file['path']);
        @unlink($file['path']);

        return $book;
    }

    /** K-12 "absorbed via CS Professional" (column F) on the office's row, and on the row of the given workbook agency. */
    private function professional(Spreadsheet $book, string $rowName): array
    {
        $sheet = $book->getSheetByName('K-12 Absorption Summary');
        $office = $agency = null;
        for ($i = 6; $i <= $sheet->getHighestDataRow('B'); $i++) {
            $name = (string) $sheet->getCell("B{$i}")->getValue();
            if ($sheet->getCell("A{$i}")->getValue() === '-' && $name === 'Biliran') {
                $office = $i;
            }
            if ($office && $name === $rowName) {
                $agency = $i;
                break;
            }
        }

        return [(float) $sheet->getCell("F{$office}")->getCalculatedValue(), (float) $sheet->getCell("F{$agency}")->getCalculatedValue()];
    }

    private function pair(array $items)
    {
        return $this->actingAs($this->psed)->put('/settings/summary-agencies', ['items' => $items]);
    }

    private function item(string $agency, string $choice): array
    {
        return ['field_office_id' => $this->office->id, 'agency' => $agency, 'choice' => $choice];
    }

    public function test_an_agency_with_no_row_is_counted_in_its_office_until_it_is_paired(): void
    {
        $this->hire(self::ODD);
        $row = $this->rows[0];

        [$office, $agency] = $this->professional($this->generate(), $row);
        $this->assertSame([1.0, 0.0], [$office, $agency], 'counted in the office row, with a note');
        $this->assertSame('Field Office-Biliran', array_key_first((new SummaryDatabase)->check(ReportPeriod::month(2026, 3))['unmatched']));

        $this->pair([$this->item(self::ODD, $row)])->assertRedirect()->assertSessionHas('status');

        [$office, $agency] = $this->professional($this->generate(), $row);
        $this->assertSame([1.0, 1.0], [$office, $agency], 'now on its own row, and still in the office total');
        $this->assertSame([], (new SummaryDatabase)->check(ReportPeriod::month(2026, 3))['unmatched']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'agency.summary_map', 'user_id' => $this->psed->id]);
    }

    public function test_an_agency_can_be_said_to_have_no_row_of_its_own(): void
    {
        $this->hire(self::ODD);

        $this->pair([$this->item(self::ODD, '__none__')])->assertRedirect();

        $this->assertNull(SummaryAgencyAlias::sole()->template_agency);
        $this->assertSame([1.0, 0.0], $this->professional($this->generate(), $this->rows[0]), 'still counted in the office');
        $this->assertSame([], (new SummaryDatabase)->check(ReportPeriod::month(2026, 3))['unmatched'], 'and no longer flagged');
    }

    public function test_a_pairing_made_by_hand_wins_over_the_automatic_match(): void
    {
        $almeria = collect($this->rows)->first(fn ($r) => str_contains($r, 'Almeria'));
        $other = $this->rows[0];
        $this->hire('Municipal Government of Almeria, Biliran');   // matches the Almeria row by name

        $this->assertSame(1.0, $this->professional($this->generate(), $almeria)[1]);

        $this->pair([$this->item('Municipal Government of Almeria, Biliran', $other)])->assertRedirect();

        $book = $this->generate();
        $this->assertSame(0.0, $this->professional($book, $almeria)[1], 'no longer on the Almeria row');
        $this->assertSame(1.0, $this->professional($book, $other)[1], 'on the row PSED chose');
    }

    public function test_clearing_a_pairing_goes_back_to_the_automatic_match(): void
    {
        $this->hire(self::ODD);
        $this->pair([$this->item(self::ODD, $this->rows[0])]);
        $this->assertSame(1, SummaryAgencyAlias::count());

        $this->pair([$this->item(self::ODD, '')])->assertRedirect();

        $this->assertSame(0, SummaryAgencyAlias::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'agency.summary_unmap']);
    }

    public function test_a_row_that_is_not_on_the_workbook_or_an_agency_the_office_does_not_have_is_refused(): void
    {
        $this->hire(self::ODD);

        $this->pair([$this->item(self::ODD, 'A row nobody has')])->assertSessionHasErrors('items.0.choice');
        $this->pair([$this->item('An agency this office never had', $this->rows[0])])->assertSessionHasErrors('items.0.agency');

        $this->assertSame(0, SummaryAgencyAlias::count());
    }

    public function test_the_screen_lists_what_needs_pairing_with_a_suggestion_and_only_psed_may_use_it(): void
    {
        $this->hire(self::ODD);
        $this->hire('Biliran Province State University (BiPSU)');   // matches by name

        $this->actingAs($this->psed)->get('/settings/summary-agencies')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Settings/SummaryAgencies')
            ->where('offices', fn ($offices) => collect($offices)->firstWhere('name', 'Field Office-Biliran')['agencies'] !== null
                && collect(collect($offices)->firstWhere('name', 'Field Office-Biliran')['agencies'])->firstWhere('agency', self::ODD)['status'] === 'unmatched'
                && collect(collect($offices)->firstWhere('name', 'Field Office-Biliran')['agencies'])->firstWhere('agency', 'Biliran Province State University (BiPSU)')['status'] === 'auto'));

        $fo = User::factory()->fieldOffice($this->office->id)->create();
        $this->actingAs($fo)->get('/settings/summary-agencies')->assertForbidden();
        $this->actingAs($fo)->put('/settings/summary-agencies', ['items' => [$this->item(self::ODD, $this->rows[0])]])->assertForbidden();
        $this->assertSame(0, SummaryAgencyAlias::count());
    }
}
