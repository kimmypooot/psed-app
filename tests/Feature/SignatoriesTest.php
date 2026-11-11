<?php

namespace Tests\Feature;

use App\Enums\ReportType;
use App\Models\AuditLog;
use App\Models\FieldOffice;
use App\Models\User;
use App\Services\ExcelReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\FakesImisDirectory;
use Tests\TestCase;

/**
 * Who signs the reports comes from IMIS. "Prepared by" is one of the office's
 * staff (chosen), "Approved / noted by" is the office's head in IMIS: the
 * Director II, or while designated the OIC or Caretaker (never typed).
 */
class SignatoriesTest extends TestCase
{
    use FakesImisDirectory;
    use RefreshDatabase;

    private FieldOffice $eastern;   // headed by an OIC in IMIS

    private FieldOffice $samar;     // headed by its Director II in IMIS

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eastern = FieldOffice::factory()->create([
            'name' => 'Field Office-Eastern Samar', 'imis_code' => 'ESFO', 'location' => 'Borongan City',
            'prepared_by_name' => null, 'prepared_by_title' => null, 'approved_by_name' => null, 'approved_by_title' => null,
        ]);
        $this->samar = FieldOffice::factory()->create([
            'name' => 'Field Office-Samar', 'imis_code' => 'SFO',
            'prepared_by_name' => null, 'prepared_by_title' => null, 'approved_by_name' => null, 'approved_by_title' => null,
        ]);
        $this->admin = User::factory()->admin()->create();
        $this->fakeImisDirectory();
    }

    private function settings(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->get('/settings/field-offices')->assertOk();
    }

    private function save(FieldOffice $office, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->put("/settings/field-offices/{$office->id}", $body + ['head_type' => $office->head_type->value]);
    }

    // ------------------------------------------------------------------ settings

    public function test_each_office_shows_its_head_from_imis_and_the_routing_rule_that_goes_with_it(): void
    {
        $this->settings()->assertInertia(function (Assert $page) {
            $page->component('Settings/FieldOffices')->where('directory.available', true);

            $offices = collect($page->toArray()['props']['offices'])->keyBy('imis_code');

            $this->assertSame('REY ALBERT B. UY', $offices['ESFO']['head']['name'], 'the Director II of Samar also heads Eastern Samar');
            $this->assertSame('Director II', $offices['ESFO']['head']['title'], 'the position alone: no (Concurrent) in the title');
            $this->assertSame('Concurrent', $offices['ESFO']['head']['capacity_label']);
            $this->assertSame('director_ii', $offices['ESFO']['suggested_head_type'], 'a Director II heading it concurrently is still the SG 26 case');
            $this->assertSame('REY ALBERT B. UY', $offices['SFO']['head']['name']);
            $this->assertSame('Director II', $offices['SFO']['head']['title']);
            $this->assertSame('director_ii', $offices['SFO']['suggested_head_type']);
        });
    }

    public function test_a_caretaker_below_director_ii_goes_with_the_sg_22_rule(): void
    {
        $people = $this->imisPeople();
        $people['heads'] = [['office_code' => 'ESFO', 'user_id' => 3, 'capacity' => 'caretaker', 'capacity_label' => 'Caretaker']];
        $this->fakeImisDirectory($people);

        $this->settings()->assertInertia(function (Assert $page) {
            $eastern = collect($page->toArray()['props']['offices'])->firstWhere('imis_code', 'ESFO');

            $this->assertSame('Supervising Human Resource Specialist', $eastern['head']['title']);
            $this->assertSame('Caretaker', $eastern['head']['capacity_label']);
            $this->assertSame('oic_sg22', $eastern['suggested_head_type']);
        });
    }

    public function test_only_the_staff_of_the_listed_offices_are_offered_for_prepared_by(): void
    {
        $staff = collect($this->settings()->viewData('page')['props']['directory']['staff']);

        $this->assertEqualsCanonicalizing([1, 3, 5, 6, 7], $staff->pluck('id')->all(), 'staff only: not PSED\'s people, and not Samar\'s head (2), who approves its reports');
        $this->assertSame('ESFO', $staff->firstWhere('id', 1)['office_code']);
    }

    public function test_the_preparer_is_chosen_from_the_offices_own_staff(): void
    {
        $this->save($this->eastern, ['prepared_by_imis_id' => 1])->assertRedirect();

        $o = $this->eastern->fresh();
        $this->assertSame(1, $o->prepared_by_imis_id);
        $this->assertSame('RYAN JOSE M. ESTOQUE', $o->prepared_by_name);
        $this->assertSame('Senior Human Resource Specialist', $o->prepared_by_title);
        $this->assertSame('REY ALBERT B. UY', $o->approved_by_name, 'the saved approver follows IMIS\'s head');
        $this->assertSame('Director II', $o->approved_by_title, 'the position alone');
    }

    public function test_someone_from_another_office_cannot_be_the_preparer(): void
    {
        $this->save($this->eastern, ['prepared_by_imis_id' => 7])   // Ana Reyes is Samar's
            ->assertSessionHasErrors(['prepared_by_imis_id' => "Choose someone from Field Office-Eastern Samar's staff in IMIS."]);
        $this->save($this->eastern, ['prepared_by_imis_id' => 4])   // PSED
            ->assertSessionHasErrors('prepared_by_imis_id');
        $this->save($this->eastern, ['prepared_by_imis_id' => 999])
            ->assertSessionHasErrors('prepared_by_imis_id');

        $this->assertNull($this->eastern->fresh()->prepared_by_imis_id);
    }

    public function test_the_head_of_an_office_cannot_be_its_preparer_because_the_head_approves(): void
    {
        // Rey Albert B. Uy is Samar's Director II and its head: he is not offered, and cannot be sent in.
        $this->save($this->samar, ['prepared_by_imis_id' => 2])
            ->assertSessionHasErrors(['prepared_by_imis_id' => 'Rey Albert B. Uy heads this office and approves its reports. Choose one of its staff to prepare them.']);
        $this->assertNull($this->samar->fresh()->prepared_by_imis_id);

        // Samar's own staff are fine.
        $this->save($this->samar, ['prepared_by_imis_id' => 7])->assertSessionHasNoErrors();
        $this->assertSame('ANA P. REYES', $this->samar->fresh()->prepared_by_name);
    }

    public function test_any_director_ii_of_the_office_is_left_out_of_the_staff_list_too(): void
    {
        $people = $this->imisPeople();
        $people['data'][] = [
            'id' => 11, 'name' => 'Dina Q. Flores', 'first_name' => null, 'middle_name' => null, 'last_name' => null, 'name_extension' => null,
            'position' => 'Director II', 'salary_grade' => 26, 'office_code' => 'ESFO', 'office_name' => 'CSC Field Office - Eastern Samar', 'office_type' => 'field_office',
        ];
        $this->fakeImisDirectory($people);

        $staff = collect($this->settings()->viewData('page')['props']['directory']['staff']);

        $this->assertNotContains(11, $staff->pluck('id')->all(), 'a Director II is a director, not staff, even when not the one heading it today');
        $this->assertContains(1, $staff->pluck('id')->all());
    }

    public function test_the_preparer_can_be_cleared(): void
    {
        $this->save($this->eastern, ['prepared_by_imis_id' => 1]);
        $this->save($this->eastern, ['prepared_by_imis_id' => ''])->assertRedirect();

        $o = $this->eastern->fresh();
        $this->assertNull($o->prepared_by_imis_id);
        $this->assertNull($o->prepared_by_name);
    }

    public function test_typed_names_are_not_accepted(): void
    {
        $this->save($this->eastern, ['prepared_by_name' => 'TYPED NAME', 'approved_by_name' => 'TYPED BOSS', 'approved_by_title' => 'Director II']);

        $o = $this->eastern->fresh();
        $this->assertNull($o->prepared_by_name);
        $this->assertNotSame('TYPED BOSS', $o->approved_by_name);
    }

    public function test_when_imis_is_down_the_rest_still_saves_but_the_people_cannot_change(): void
    {
        $this->save($this->eastern, ['prepared_by_imis_id' => 1]);
        $this->imisIsDown();

        $this->save($this->eastern, ['head_type' => 'oic_sg22', 'location' => 'Borongan'])->assertSessionHasNoErrors();
        $this->assertSame('oic_sg22', $this->eastern->fresh()->head_type->value);
        $this->assertSame('RYAN JOSE M. ESTOQUE', $this->eastern->fresh()->prepared_by_name, 'what was saved is kept');

        $this->save($this->eastern, ['head_type' => 'oic_sg22', 'prepared_by_imis_id' => 3])->assertSessionHasErrors('prepared_by_imis_id');
        $this->assertSame(1, $this->eastern->fresh()->prepared_by_imis_id);

        $this->settings()->assertInertia(fn (Assert $p) => $p->where('directory.available', false)->where('directory.message', fn ($m) => str_contains($m, 'IMIS')));
    }

    public function test_the_change_is_in_the_audit_trail(): void
    {
        $this->save($this->eastern, ['prepared_by_imis_id' => 1]);

        $log = AuditLog::where('action', 'field_office.update')->sole();
        $this->assertNull($log->before['prepared_by_imis_id']);
        $this->assertSame(1, $log->after['prepared_by_imis_id']);
    }

    // ------------------------------------------------------------------- reports

    private function skipWithoutTemplates(): void
    {
        try {
            (new ExcelReportService)->templatePath(ReportType::Mrap);
            (new ExcelReportService)->templatePath(ReportType::Absorption);
        } catch (\RuntimeException) {
            $this->markTestSkipped('The report templates are not in storage/app/templates.');
        }
    }

    private function sheet(ReportType $type, ?FieldOffice $office): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        $file = (new ExcelReportService)->generate($type, $office, 2026, 10);
        $sheet = IOFactory::load($file['path'])->getSheet(0);
        @unlink($file['path']);

        return $sheet;
    }

    public function test_the_mrap_is_signed_by_the_chosen_preparer_and_the_head_in_imis(): void
    {
        $this->skipWithoutTemplates();
        $this->eastern->update(['prepared_by_imis_id' => 1]);

        $sheet = $this->sheet(ReportType::Mrap, $this->eastern);

        $this->assertSame('RYAN JOSE M. ESTOQUE', $sheet->getCell('C37')->getValue());
        $this->assertSame('Senior Human Resource Specialist', $sheet->getCell('C38')->getValue());
        $this->assertSame('REY ALBERT B. UY', $sheet->getCell('M37')->getValue());
        $this->assertSame('Director II', $sheet->getCell('M38')->getValue(), 'heading two offices, he still signs by his position alone');

        $samar = $this->sheet(ReportType::Mrap, $this->samar);
        $this->assertSame('REY ALBERT B. UY', $samar->getCell('M37')->getValue());
        $this->assertSame('Director II', $samar->getCell('M38')->getValue(), 'the Director II signs by position');
        $this->assertEmpty((string) $samar->getCell('C37')->getValue(), 'no preparer chosen: left for a signature');
    }

    public function test_the_absorption_report_is_signed_the_same_way(): void
    {
        $this->skipWithoutTemplates();
        $this->eastern->update(['prepared_by_imis_id' => 1]);

        $sheet = $this->sheet(ReportType::Absorption, $this->eastern);

        $this->assertSame('RYAN JOSE M. ESTOQUE', (string) $sheet->getCell('B44')->getValue());
        $this->assertSame('Senior Human Resource Specialist', (string) $sheet->getCell('B45')->getValue());
        $this->assertSame('REY ALBERT B. UY', (string) $sheet->getCell('H44')->getValue());
        $this->assertSame('Director II', (string) $sheet->getCell('H45')->getValue());
    }

    public function test_imis_s_head_today_beats_the_name_saved_last_month(): void
    {
        $this->skipWithoutTemplates();
        $this->samar->update(['approved_by_name' => 'FORMER DIRECTOR', 'approved_by_title' => 'Director II']);

        $this->assertSame('REY ALBERT B. UY', $this->sheet(ReportType::Mrap, $this->samar)->getCell('M37')->getValue());
    }

    public function test_when_imis_is_down_the_saved_names_are_printed(): void
    {
        $this->skipWithoutTemplates();
        $this->eastern->update([
            'prepared_by_imis_id' => 1, 'prepared_by_name' => 'RYAN JOSE M. ESTOQUE', 'prepared_by_title' => 'Senior Human Resource Specialist',
            'approved_by_name' => 'MARIA L. SANTOS', 'approved_by_title' => 'Officer-in-Charge',
        ]);
        $this->imisIsDown();

        $sheet = $this->sheet(ReportType::Mrap, $this->eastern);

        $this->assertSame('RYAN JOSE M. ESTOQUE', $sheet->getCell('C37')->getValue());
        $this->assertSame('MARIA L. SANTOS', $sheet->getCell('M37')->getValue());
        $this->assertSame('Officer-in-Charge', $sheet->getCell('M38')->getValue());
    }

    public function test_the_template_s_own_names_never_leak_into_another_office(): void
    {
        $this->skipWithoutTemplates();
        $this->imisIsDown();                                // and nothing saved for this office

        $sheet = $this->sheet(ReportType::Mrap, $this->samar);

        $this->assertEmpty((string) $sheet->getCell('C37')->getValue());
        $this->assertEmpty((string) $sheet->getCell('M37')->getValue());
        $this->assertStringNotContainsString('ESTOQUE', strtoupper((string) $sheet->getCell('C37')->getValue()));
    }

    public function test_a_consolidated_report_is_signed_by_hand(): void
    {
        $this->skipWithoutTemplates();

        $sheet = $this->sheet(ReportType::Mrap, null);

        $this->assertEmpty((string) $sheet->getCell('C37')->getValue());
        $this->assertEmpty((string) $sheet->getCell('M37')->getValue());
    }
}
