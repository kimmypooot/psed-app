<?php

namespace Tests\Feature;

use App\Enums\HeadType;
use App\Models\Appointment;
use App\Models\FieldOffice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AppointmentsTest extends TestCase
{
    use RefreshDatabase;

    private FieldOffice $office;     // headed by a Director II: PSED from SG 26

    private FieldOffice $other;      // default case: PSED from SG 21

    private User $fo;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->office = FieldOffice::factory()->headedBy(HeadType::DirectorII)->create(['name' => 'Field Office-Eastern Samar']);
        $this->other = FieldOffice::factory()->create(['name' => 'Field Office-Samar']);
        $this->fo = User::factory()->fieldOffice($this->office->id)->create();
        $this->admin = User::factory()->admin()->create();
    }

    /** A valid form payload; override what a test cares about. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'agency' => 'DepEd Eastern Samar Division',
            'date_received' => '2026-10-05',
            'last_name' => 'Dela Cruz',
            'first_name' => 'Juan',
            'sex' => 'M',
            'position' => 'Administrative Aide I',
            'salary_grade' => 1,
            'employment_status' => 'Permanent',
            'nature_of_appointment' => 'Original',
        ], $overrides);
    }

    // ------------------------------------------------------------- access

    public function test_guests_are_sent_to_the_sign_in_page(): void
    {
        foreach (['/dashboard', '/appointments', '/appointments/create', '/reports'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    public function test_a_field_office_user_only_sees_their_own_offices_appointments(): void
    {
        Appointment::factory()->create(['field_office_id' => $this->office->id, 'last_name' => 'Mine']);
        Appointment::factory()->create(['field_office_id' => $this->other->id, 'last_name' => 'Theirs']);

        $this->actingAs($this->fo)->get('/appointments')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Appointments/Index')
                ->has('appointments.data', 1)
                ->where('appointments.data.0.full_name', fn ($n) => str_starts_with($n, 'Mine')));

        $this->actingAs($this->admin)->get('/appointments')
            ->assertInertia(fn (Assert $page) => $page->has('appointments.data', 2));
    }

    public function test_a_field_office_user_cannot_open_or_change_another_offices_appointment(): void
    {
        $theirs = Appointment::factory()->create(['field_office_id' => $this->other->id]);

        $this->actingAs($this->fo)->get("/appointments/{$theirs->id}/edit")->assertForbidden();
        $this->actingAs($this->fo)->put("/appointments/{$theirs->id}", $this->payload())->assertForbidden();
        $this->actingAs($this->fo)->delete("/appointments/{$theirs->id}")->assertForbidden();
        $this->assertModelExists($theirs);
    }

    // ------------------------------------------------------------ routing

    public function test_saving_routes_by_the_offices_head_and_the_salary_grade(): void
    {
        $this->actingAs($this->fo);

        $this->post('/appointments', $this->payload(['salary_grade' => 25, 'last_name' => 'A']))->assertRedirect('/appointments');
        $this->post('/appointments', $this->payload(['salary_grade' => 26, 'last_name' => 'B']))->assertRedirect('/appointments');

        $this->assertSame('field_office', Appointment::where('last_name', 'A')->value('processed_by')->value);
        $this->assertSame('psed', Appointment::where('last_name', 'B')->value('processed_by')->value);

        // The same SG 24 in the default-case office goes to PSED (from SG 21).
        $this->actingAs($this->admin)->post('/appointments', $this->payload(['field_office_id' => $this->other->id, 'salary_grade' => 24, 'last_name' => 'C']));
        $this->assertSame('psed', Appointment::where('last_name', 'C')->first()->processed_by->value);
    }

    public function test_the_list_names_the_specific_office_that_processes_each_appointment(): void
    {
        Appointment::factory()->create(['field_office_id' => $this->office->id, 'salary_grade' => 10, 'last_name' => 'Aaa']);   // Eastern Samar processes
        Appointment::factory()->create(['field_office_id' => $this->other->id, 'salary_grade' => 10, 'last_name' => 'Bbb']);    // Samar processes
        Appointment::factory()->create(['field_office_id' => $this->other->id, 'salary_grade' => 24, 'last_name' => 'Ccc']);    // PSED (default case, SG 21+)

        $labels = collect($this->actingAs($this->admin)->get('/appointments?sort=name&dir=asc')
            ->viewData('page')['props']['appointments']['data'])
            ->mapWithKeys(fn ($row) => [substr($row['full_name'], 0, 3) => $row['processed_by_label']]);

        $this->assertSame('Field Office-Eastern Samar', $labels['Aaa']);
        $this->assertSame('Field Office-Samar', $labels['Bbb']);
        $this->assertSame('PSED', $labels['Ccc']);
    }

    public function test_the_view_dialog_gets_the_full_details_of_an_appointment(): void
    {
        $a = Appointment::factory()->withEligibility('RA 1080 (Teacher)')->create([
            'field_office_id' => $this->office->id, 'salary_grade' => 27, 'last_name' => 'Reyes', 'first_name' => 'Ana',
            'date_received' => '2026-10-05', 'is_first_time_entrant' => true,
        ]);

        $json = $this->actingAs($this->fo)->getJson("/appointments/{$a->id}/details")->assertOk()->json();

        $this->assertSame('PSED', $json['processed_by_label'], 'SG 27 at a Director II office goes to PSED');
        $this->assertTrue($json['is_first_time_entrant']);
        $this->assertTrue($json['can_update']);
        $this->assertStringContainsString('Field Office-Eastern Samar', $json['routing_note']);
        $this->assertSame(['Receipt', 'Appointee', 'Position and appointment', 'Eligibility', 'CSC action'], array_column($json['sections'], 'title'));

        $items = collect($json['sections'])->flatMap(fn ($s) => $s['items'])->pluck('value', 'label');
        $this->assertSame('Reyes, Ana '.$a->middle_name, $items['Name']);
        $this->assertSame('Oct 5, 2026', $items['Date received']);
        $this->assertSame('SG 27', $items['Salary grade']);
        $this->assertSame('RA 1080 (Teacher)', $items['Type of eligibility']);
        $this->assertSame('Awaiting action', $items['Action']);
    }

    // ------------------------------------------- K-12 graduate and SK position

    public function test_an_appointment_can_be_marked_as_a_k12_graduate(): void
    {
        $this->actingAs($this->fo)->post('/appointments', $this->payload(['is_k12_graduate' => true]))->assertSessionHasNoErrors();

        $a = Appointment::firstOrFail();
        $this->assertTrue($a->is_k12_graduate);
        $this->assertFalse(Appointment::factory()->make()->is_k12_graduate, 'not a K-12 graduate unless marked');

        $items = collect($this->actingAs($this->fo)->getJson("/appointments/{$a->id}/details")->json('sections'))->flatMap(fn ($s) => $s['items'])->pluck('value', 'label');
        $this->assertSame('Yes', $items['K-12 graduate']);
    }

    public function test_the_skoe_asks_for_the_sk_position_and_keeps_the_one_chosen(): void
    {
        $skoe = config('psed.skoe.eligibility');

        $this->actingAs($this->fo)->post('/appointments', $this->payload(['eligibility_type' => $skoe]))
            ->assertSessionHasErrors('skoe_position');

        foreach (['SK Official', 'SK Secretary', 'SK Treasurer'] as $i => $position) {
            $this->actingAs($this->fo)->post('/appointments', $this->payload(['eligibility_type' => $skoe, 'skoe_position' => $position, 'last_name' => "SK{$i}"]))
                ->assertSessionHasNoErrors();
            $this->assertSame($position, Appointment::where('last_name', "SK{$i}")->value('skoe_position'));
        }

        $this->actingAs($this->fo)->post('/appointments', $this->payload(['eligibility_type' => $skoe, 'skoe_position' => 'SK Chairperson']))
            ->assertSessionHasErrors('skoe_position');
    }

    public function test_the_sk_position_is_dropped_when_the_eligibility_is_not_the_skoe(): void
    {
        $a = Appointment::factory()->skoe('SK Treasurer')->create(['field_office_id' => $this->office->id]);

        $this->actingAs($this->fo)->put("/appointments/{$a->id}", $this->payload(['eligibility_type' => 'CS Professional', 'skoe_position' => 'SK Treasurer']))
            ->assertSessionHasNoErrors();

        $this->assertNull($a->fresh()->skoe_position);
    }

    public function test_the_form_is_given_the_skoe_and_its_positions(): void
    {
        $this->actingAs($this->fo)->get('/appointments/create')->assertInertia(fn (Assert $page) => $page
            ->where('options.skoe_eligibility', config('psed.skoe.eligibility'))
            ->where('options.skoe_position', ['SK Official', 'SK Secretary', 'SK Treasurer']));
    }

    public function test_the_view_dialog_respects_who_may_see_the_appointment(): void
    {
        $theirs = Appointment::factory()->create(['field_office_id' => $this->other->id]);

        $this->actingAs($this->fo)->getJson("/appointments/{$theirs->id}/details")->assertForbidden();
        $this->actingAs($this->admin)->getJson("/appointments/{$theirs->id}/details")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson("/appointments/{$theirs->id}/details")->assertUnauthorized();
    }

    public function test_the_confirmation_after_encoding_names_the_processing_office(): void
    {
        $this->actingAs($this->fo)->post('/appointments', $this->payload(['salary_grade' => 5]))
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'routed to Field Office-Eastern Samar.'));
    }

    public function test_a_field_office_user_cannot_encode_into_another_office(): void
    {
        $this->actingAs($this->fo)->post('/appointments', $this->payload(['field_office_id' => $this->other->id]));

        $this->assertSame($this->office->id, Appointment::sole()->field_office_id);
    }

    public function test_changing_the_salary_grade_reroutes_the_appointment(): void
    {
        $a = Appointment::factory()->create(['field_office_id' => $this->office->id, 'salary_grade' => 10]);
        $this->assertSame('field_office', $a->fresh()->processed_by->value);

        $this->actingAs($this->fo)->put("/appointments/{$a->id}", $this->payload(['salary_grade' => 27]))->assertRedirect('/appointments');

        $this->assertSame('psed', $a->fresh()->processed_by->value);
    }

    public function test_changing_an_offices_head_reroutes_its_existing_appointments(): void
    {
        $sg22 = Appointment::factory()->create(['field_office_id' => $this->office->id, 'salary_grade' => 22]);
        $this->assertSame('field_office', $sg22->fresh()->processed_by->value, 'Director II: SG 22 stays in the Field Office');

        $this->actingAs($this->admin)->put("/settings/field-offices/{$this->office->id}", [
            'head_type' => 'oic_sg22',
        ])->assertRedirect();

        $this->assertSame('psed', $sg22->fresh()->processed_by->value, 'OIC SG 22: SG 22 now goes to PSED');
    }

    // ------------------------------------------------- recording the action

    public function test_the_field_office_records_the_action_on_appointments_it_processes(): void
    {
        $a = Appointment::factory()->create(['field_office_id' => $this->office->id, 'salary_grade' => 10]);

        $this->actingAs($this->fo)->put("/appointments/{$a->id}", $this->payload([
            'salary_grade' => 10, 'csc_action' => 'Approved', 'action_date' => '2026-10-12', 'action_officer' => 'Rey Uy',
        ]));

        $this->assertSame('Approved', $a->fresh()->csc_action);
        $this->assertSame('2026-10-12', $a->fresh()->action_date->toDateString());
    }

    public function test_the_field_office_cannot_record_the_action_on_appointments_psed_processes(): void
    {
        $a = Appointment::factory()->create(['field_office_id' => $this->office->id, 'salary_grade' => 27, 'last_name' => 'Old']);

        $this->actingAs($this->fo)->put("/appointments/{$a->id}", $this->payload([
            'salary_grade' => 27, 'last_name' => 'New', 'csc_action' => 'Approved', 'action_date' => '2026-10-12',
        ]))->assertRedirect('/appointments');

        $a->refresh();
        $this->assertSame('New', $a->last_name, 'the details can still be corrected');
        $this->assertNull($a->csc_action, 'but the decision is PSED\'s to record');
        $this->assertNull($a->action_date);

        // PSED can.
        $this->actingAs($this->admin)->put("/appointments/{$a->id}", $this->payload([
            'field_office_id' => $this->office->id, 'salary_grade' => 27, 'csc_action' => 'Approved', 'action_date' => '2026-10-13',
        ]));
        $this->assertSame('Approved', $a->fresh()->csc_action);
    }

    public function test_psed_and_administrators_can_alter_any_appointment_and_it_is_audited(): void
    {
        foreach ([$this->admin] as $i => $user) {
            // Processed by the Field Office (SG 10), already approved by it.
            $a = Appointment::factory()->acted('2026-10-10')->create(['field_office_id' => $this->office->id, 'salary_grade' => 10, 'position' => 'Original']);

            $this->actingAs($user)->put("/appointments/{$a->id}", $this->payload([
                'field_office_id' => $this->other->id,
                'salary_grade' => 24,
                'position' => 'Corrected by PSED',
                'csc_action' => 'Invalidated',
                'action_date' => '2026-10-20',
                'disapproval_reason' => 'Overridden on review.',
            ]))->assertRedirect('/appointments');

            $a->refresh();
            $this->assertSame('Corrected by PSED', $a->position);
            $this->assertSame('Invalidated', $a->csc_action, 'the Field Office\'s decision can be overridden');
            $this->assertSame($this->other->id, $a->field_office_id, 'and the office corrected');
            $this->assertSame('psed', $a->processed_by->value, 'SG 24 in the default-case office goes to PSED');

            $log = \App\Models\AuditLog::where('action', 'appointment.update')->where('subject_id', $a->id)->latest('id')->first();
            $this->assertSame($user->id, $log->user_id);
            $this->assertSame('Original', $log->before['position'], 'the audit trail keeps what it was');
            $this->assertSame('Corrected by PSED', $log->after['position']);

            $this->actingAs($user)->delete("/appointments/{$a->id}")->assertRedirect('/appointments');
            $this->assertModelMissing($a);
        }
    }

    public function test_an_action_needs_its_date_and_a_disapproval_needs_its_reason(): void
    {
        $this->actingAs($this->fo)->post('/appointments', $this->payload(['csc_action' => 'Disapproved']))
            ->assertSessionHasErrors(['action_date', 'disapproval_reason']);
    }

    public function test_a_field_office_cannot_delete_an_appointment_already_acted_upon(): void
    {
        $acted = Appointment::factory()->acted('2026-10-10')->create(['field_office_id' => $this->office->id]);
        $open = Appointment::factory()->create(['field_office_id' => $this->office->id]);

        $this->actingAs($this->fo)->delete("/appointments/{$acted->id}")->assertForbidden();
        $this->actingAs($this->fo)->delete("/appointments/{$open->id}")->assertRedirect('/appointments');

        $this->assertModelExists($acted);
        $this->assertModelMissing($open);
    }

    // ------------------------------------------------------------ filters

    public function test_the_list_filters_by_search_sg_agency_and_date(): void
    {
        $make = fn (array $a) => Appointment::factory()->create($a + ['field_office_id' => $this->office->id]);
        $make(['last_name' => 'Abella', 'salary_grade' => 11, 'agency' => 'MG Oras', 'date_received' => '2026-10-02']);
        $make(['last_name' => 'Bautista', 'salary_grade' => 24, 'agency' => 'MG Taft', 'date_received' => '2026-10-20']);
        $make(['last_name' => 'Cruz', 'salary_grade' => 24, 'agency' => 'MG Oras', 'date_received' => '2026-10-25']);

        $count = fn (string $query) => $this->actingAs($this->fo)->get("/appointments?{$query}")
            ->viewData('page')['props']['appointments']['total'];

        $this->assertSame(1, $count('search=Abella'));
        $this->assertSame(2, $count('sg=24'));
        $this->assertSame(2, $count('agency=Oras'));
        $this->assertSame(2, $count('date_from=2026-10-15'));
        $this->assertSame(1, $count('date_from=2026-10-15&date_to=2026-10-22'));
        $this->assertSame(1, $count('sg=24&agency=Oras'));
        // A Director II office sends only SG 26 and above to PSED, and none of these is.
        $this->assertSame(0, $count('processor=psed'));
        $this->assertSame(3, $count('processor=field_office'));
    }

    // ---------------------------------------------------------- settings

    public function test_only_psed_administrators_manage_field_office_settings(): void
    {
        $this->actingAs($this->fo)->get('/settings/field-offices')->assertForbidden();
        $this->actingAs($this->admin)->get('/settings/field-offices')->assertOk();

        $this->actingAs($this->fo)->put("/settings/field-offices/{$this->office->id}", ['head_type' => 'none'])->assertForbidden();
        $this->assertSame(HeadType::DirectorII, $this->office->fresh()->head_type);
    }

    // ------------------------------------------------------ pages render

    public function test_the_pages_render_for_each_role(): void
    {
        Appointment::factory()->count(3)->create(['field_office_id' => $this->office->id]);

        foreach ([$this->fo, $this->admin] as $user) {
            $this->actingAs($user);
            $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Dashboard')->has('stats'));
            $this->get('/appointments')->assertOk();
            $this->get('/appointments/create')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Appointments/Create')->has('options.sector'));
            $this->get('/reports')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Reports/Index')->has('types', 4));
        }
    }

    public function test_the_position_search_finds_titles_from_the_reference_list(): void
    {
        \App\Models\ReferenceValue::create(['type' => 'position', 'value' => 'Administrative Aide I']);
        \App\Models\ReferenceValue::create(['type' => 'position', 'value' => 'Accountant II']);

        $this->actingAs($this->fo)->getJson('/lookups/positions?q=aide')->assertOk()->assertExactJson(['Administrative Aide I']);
    }
}
