<?php

namespace Tests\Feature;

use App\Enums\HeadType;
use App\Models\Appointment;
use App\Models\FieldOffice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesImisDirectory;
use Tests\TestCase;

/**
 * The action officer is an employee of CSC RO VIII, taken from IMIS: chosen,
 * never typed, and the name stored is IMIS's.
 */
class ActionOfficerTest extends TestCase
{
    use FakesImisDirectory;
    use RefreshDatabase;

    private FieldOffice $office;

    private User $fo;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->office = FieldOffice::factory()->create(['name' => 'Field Office-Eastern Samar', 'imis_code' => 'ESFO']);
        $this->fo = User::factory()->fieldOffice($this->office->id)->create();
        $this->admin = User::factory()->admin()->create();
        $this->fakeImisDirectory();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'agency' => 'DepEd Eastern Samar Division', 'date_received' => '2026-10-05',
            'last_name' => 'Reyes', 'first_name' => 'Ana', 'sex' => 'F',
            'position' => 'Administrative Aide I', 'salary_grade' => 1,
            'employment_status' => 'Permanent', 'nature_of_appointment' => 'Original',
            'csc_action' => 'Approved', 'action_date' => '2026-10-12',
        ], $overrides);
    }

    public function test_the_officer_is_chosen_from_imis_and_the_name_is_imis_s_not_the_browser_s(): void
    {
        $this->actingAs($this->fo)->post('/appointments', $this->payload([
            'action_officer_imis_id' => '1',
            'action_officer' => 'Typed By Someone',     // a typed name is never accepted
        ]))->assertRedirect('/appointments');

        $a = Appointment::sole();
        $this->assertSame(1, $a->action_officer_imis_id);
        $this->assertSame('Ryan Jose M. Estoque', $a->action_officer);
    }

    public function test_a_typed_name_alone_is_ignored(): void
    {
        $this->actingAs($this->fo)->post('/appointments', $this->payload(['action_officer' => 'Typed By Someone']))->assertRedirect('/appointments');

        $this->assertNull(Appointment::sole()->action_officer);
        $this->assertNull(Appointment::sole()->action_officer_imis_id);
    }

    public function test_someone_who_is_not_an_imis_employee_is_refused(): void
    {
        $this->actingAs($this->fo)->post('/appointments', $this->payload(['action_officer_imis_id' => '999']))
            ->assertSessionHasErrors(['action_officer_imis_id' => 'Choose an employee of your office from the list.']);
        $this->actingAs($this->fo)->post('/appointments', $this->payload(['action_officer_imis_id' => 'abc']))
            ->assertSessionHasErrors('action_officer_imis_id');

        $this->assertSame(0, Appointment::count());
    }

    public function test_the_officer_can_be_changed_and_cleared(): void
    {
        $a = Appointment::factory()->create(['field_office_id' => $this->office->id, 'action_officer' => 'Ryan Jose M. Estoque', 'action_officer_imis_id' => 1,
            'csc_action' => 'Approved', 'action_date' => '2026-10-12']);

        $this->actingAs($this->fo)->put("/appointments/{$a->id}", $this->payload(['action_officer_imis_id' => '3']))->assertRedirect('/appointments');
        $this->assertSame('Maria L. Santos', $a->fresh()->action_officer);
        $this->assertSame(3, $a->fresh()->action_officer_imis_id);

        $this->actingAs($this->fo)->put("/appointments/{$a->id}", $this->payload(['action_officer_imis_id' => '']))->assertRedirect('/appointments');
        $this->assertNull($a->fresh()->action_officer);
        $this->assertNull($a->fresh()->action_officer_imis_id);
    }

    public function test_an_officer_saved_before_the_list_came_from_imis_is_kept_unless_changed(): void
    {
        $a = Appointment::factory()->create(['field_office_id' => $this->office->id, 'action_officer' => 'Typed Earlier', 'csc_action' => 'Approved', 'action_date' => '2026-10-12']);

        $this->actingAs($this->fo)->put("/appointments/{$a->id}", $this->payload(['action_officer_imis_id' => 'keep', 'position' => 'Clerk II']));
        $this->assertSame('Typed Earlier', $a->fresh()->action_officer);
        $this->assertSame('Clerk II', $a->fresh()->position);

        $this->actingAs($this->fo)->put("/appointments/{$a->id}", $this->payload());      // the field not sent at all
        $this->assertSame('Typed Earlier', $a->fresh()->action_officer);
    }

    public function test_an_officer_who_has_since_left_imis_does_not_block_an_unrelated_edit(): void
    {
        $a = Appointment::factory()->create(['field_office_id' => $this->office->id, 'action_officer' => 'Gone Person', 'action_officer_imis_id' => 99,
            'csc_action' => 'Approved', 'action_date' => '2026-10-12']);

        $this->actingAs($this->fo)->put("/appointments/{$a->id}", $this->payload(['action_officer_imis_id' => '99', 'position' => 'Clerk II']))
            ->assertRedirect('/appointments');

        $this->assertSame('Gone Person', $a->fresh()->action_officer);
        $this->assertSame('Clerk II', $a->fresh()->position);
    }

    public function test_when_imis_is_down_the_officer_cannot_be_picked_but_everything_else_still_saves(): void
    {
        $this->imisIsDown();

        $this->actingAs($this->fo)->post('/appointments', $this->payload(['action_officer_imis_id' => '1']))
            ->assertSessionHasErrors('action_officer_imis_id');
        $this->assertSame(0, Appointment::count());

        $this->actingAs($this->fo)->post('/appointments', $this->payload())->assertRedirect('/appointments');
        $this->assertSame(1, Appointment::count());
    }

    public function test_a_field_office_cannot_name_the_officer_where_psed_records_the_action(): void
    {
        $this->office->update(['head_type' => HeadType::DirectorII]);   // SG 27 goes to PSED here

        $this->actingAs($this->fo)->post('/appointments', $this->payload(['salary_grade' => 27, 'action_officer_imis_id' => '1']))->assertRedirect('/appointments');

        $a = Appointment::sole();
        $this->assertNull($a->csc_action);
        $this->assertNull($a->action_officer_imis_id);
    }

    public function test_the_form_is_given_the_imis_employees_grouped_by_office(): void
    {
        $page = fn (string $url) => $this->actingAs($this->fo)->get($url)->assertOk();

        $page('/appointments/create')->assertInertia(fn (Assert $p) => $p
            ->component('Appointments/Create')
            ->where('actionOfficers.error', null)
            ->has('actionOfficers.groups', 1)                                   // only their own office
            ->where('actionOfficers.groups.0.label', 'CSC Field Office - Eastern Samar')
            ->where('actionOfficers.groups.0.options.0', ['value' => '2', 'label' => 'Rey Albert B. Uy · Director II']));   // highest salary grade first: the Director II holding the office concurrently

        $a = Appointment::factory()->create(['field_office_id' => $this->office->id, 'action_officer' => 'Ryan Jose M. Estoque', 'action_officer_imis_id' => 1]);
        $page("/appointments/{$a->id}/edit")->assertInertia(fn (Assert $p) => $p
            ->where('appointment.action_officer_imis_id', 1)
            ->has('actionOfficers.groups'));
    }

    public function test_for_what_psed_processes_the_choices_are_the_regional_offices_directors_highest_grade_first(): void
    {
        $directors = $this->actingAs($this->admin)->get('/appointments/create')->viewData('page')['props']['actionOfficers']['directors'];

        $this->assertCount(1, $directors);
        $this->assertSame('Office of the Regional Director', $directors[0]['label']);
        $this->assertSame(
            [['value' => '8', 'label' => 'Alicia R. Mendoza · Director IV'], ['value' => '9', 'label' => 'Benito C. Lim · Director III']],
            $directors[0]['options'],
            'Director IV (SG 28) above Director III (SG 27), and nobody else of that office',
        );
    }

    public function test_an_appointment_psed_processes_takes_a_director_as_its_action_officer(): void
    {
        $this->office->update(['head_type' => HeadType::DirectorII]);   // SG 27 goes to PSED here

        $this->actingAs($this->admin)->post('/appointments', $this->payload(['field_office_id' => $this->office->id, 'salary_grade' => 27, 'action_officer_imis_id' => '8']))
            ->assertRedirect('/appointments');

        $a = Appointment::sole();
        $this->assertSame('psed', $a->processed_by->value);
        $this->assertSame('Alicia R. Mendoza', $a->action_officer);
        $this->assertSame(8, $a->action_officer_imis_id);
    }

    public function test_an_appointment_psed_processes_does_not_take_a_field_office_employee_or_another_of_the_regional_office(): void
    {
        $this->office->update(['head_type' => HeadType::DirectorII]);
        $psed = fn (string $id) => $this->actingAs($this->admin)->post('/appointments', $this->payload([
            'field_office_id' => $this->office->id, 'salary_grade' => 27, 'action_officer_imis_id' => $id, 'last_name' => 'Try'.$id,
        ]));

        $psed('1')->assertSessionHasErrors(['action_officer_imis_id' => 'Choose the Director IV or Director III from the list.']);   // Field Office staff
        $psed('10')->assertSessionHasErrors('action_officer_imis_id');                                                                    // the regional office's aide
        $psed('2')->assertSessionHasErrors('action_officer_imis_id');                                                                     // a Director II

        $this->assertSame(0, Appointment::count());
    }

    public function test_an_appointment_the_field_office_processes_does_not_take_a_director(): void
    {
        $this->office->update(['head_type' => HeadType::DirectorII]);   // SG 5 stays in the Field Office

        $this->actingAs($this->admin)->post('/appointments', $this->payload(['field_office_id' => $this->office->id, 'salary_grade' => 5, 'action_officer_imis_id' => '8']))
            ->assertSessionHasErrors(['action_officer_imis_id' => 'Choose an employee of a Field Office from the list.']);

        $this->assertSame(0, Appointment::count());
    }

    public function test_a_field_office_user_is_offered_only_their_own_offices_employees(): void
    {
        $groups = $this->actingAs($this->fo)->get('/appointments/create')->viewData('page')['props']['actionOfficers']['groups'];

        $this->assertSame(['CSC Field Office - Eastern Samar'], array_column($groups, 'label'));
        $this->assertSame(['2', '3', '1', '5'], array_column($groups[0]['options'], 'value'), 'highest salary grade first, starting with the Director II who holds the office concurrently; Samar\'s own people are not offered');
    }

    public function test_an_administrator_is_offered_every_field_office_but_not_regional_support_units(): void
    {
        $groups = $this->actingAs($this->admin)->get('/appointments/create')->viewData('page')['props']['actionOfficers']['groups'];

        $this->assertSame(['CSC Field Office - Eastern Samar', 'CSC Field Office - Samar'], array_column($groups, 'label'), 'no PSED group');
        $values = collect($groups)->flatMap(fn ($g) => array_column($g['options'], 'value'))->all();
        $this->assertNotContains('4', $values, 'Pedro of PSED is not listed');
        $this->assertContains('7', $values, 'but Samar\'s staff are, for an administrator');
    }

    public function test_the_director_holding_an_office_concurrently_is_listed_there_and_can_be_named(): void
    {
        // The Director II of Samar (id 2) holds Eastern Samar too: he is in both offices' lists.
        $groups = collect($this->actingAs($this->admin)->get('/appointments/create')->viewData('page')['props']['actionOfficers']['groups'])->keyBy('label');
        $values = fn (string $office) => array_column($groups[$office]['options'], 'value');

        $this->assertContains('2', $values('CSC Field Office - Eastern Samar'), 'in the office he holds');
        $this->assertContains('2', $values('CSC Field Office - Samar'), 'and in the one he works in');

        // A Field Office user of Eastern Samar can name him as the action officer.
        $this->actingAs($this->fo)->post('/appointments', $this->payload(['action_officer_imis_id' => '2']))->assertRedirect('/appointments');
        $a = Appointment::sole();
        $this->assertSame('Rey Albert B. Uy', $a->action_officer);
        $this->assertSame(2, $a->action_officer_imis_id);
    }

    public function test_a_field_office_user_cannot_name_someone_from_another_office_by_sending_their_id(): void
    {
        $this->actingAs($this->fo)->post('/appointments', $this->payload(['action_officer_imis_id' => '7']))   // Ana Reyes, of Samar
            ->assertSessionHasErrors(['action_officer_imis_id' => 'Choose an employee of your office from the list.']);
        $this->actingAs($this->fo)->post('/appointments', $this->payload(['action_officer_imis_id' => '4']))   // Pedro, of PSED
            ->assertSessionHasErrors('action_officer_imis_id');

        $this->assertSame(0, Appointment::count());
    }

    public function test_an_administrator_can_name_any_field_office_employee_but_not_a_regional_support_unit_one(): void
    {
        $this->actingAs($this->admin)->post('/appointments', $this->payload(['field_office_id' => $this->office->id, 'action_officer_imis_id' => '7']))
            ->assertRedirect('/appointments');
        $this->assertSame('Ana P. Reyes', Appointment::sole()->action_officer);

        $this->actingAs($this->admin)->post('/appointments', $this->payload(['field_office_id' => $this->office->id, 'action_officer_imis_id' => '4', 'last_name' => 'Other']))
            ->assertSessionHasErrors(['action_officer_imis_id' => 'Choose an employee of a Field Office from the list.']);
        $this->assertSame(1, Appointment::count());
    }

    public function test_an_officer_an_administrator_chose_in_another_office_does_not_block_a_field_office_edit(): void
    {
        $a = Appointment::factory()->create(['field_office_id' => $this->office->id, 'action_officer' => 'Ana P. Reyes', 'action_officer_imis_id' => 7,
            'csc_action' => 'Approved', 'action_date' => '2026-10-12']);

        $this->actingAs($this->fo)->put("/appointments/{$a->id}", $this->payload(['action_officer_imis_id' => '7', 'position' => 'Clerk II']))->assertRedirect('/appointments');

        $this->assertSame('Ana P. Reyes', $a->fresh()->action_officer);
        $this->assertSame('Clerk II', $a->fresh()->position);
    }

    public function test_the_form_says_why_when_imis_cannot_be_reached(): void
    {
        $this->imisIsDown();

        $this->actingAs($this->fo)->get('/appointments/create')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('actionOfficers.groups', 0)
            ->where('actionOfficers.error', fn ($m) => str_contains($m, 'IMIS')));
    }

    // ---------------------------------------------------------- received by

    public function test_received_by_is_chosen_from_the_field_office_employees_and_the_name_is_imis_s(): void
    {
        $this->actingAs($this->fo)->post('/appointments', $this->payload([
            'received_by_imis_id' => '3',
            'received_by' => 'Typed By Someone',        // a typed name is never accepted
        ]))->assertRedirect('/appointments');

        $a = Appointment::sole();
        $this->assertSame(3, $a->received_by_imis_id);
        $this->assertSame('Maria L. Santos', $a->received_by);
    }

    public function test_received_by_does_not_take_the_regional_office_s_directors_or_another_office_s_staff(): void
    {
        // Benito C. Lim (9) is the Director III of the Office of the Regional Director.
        $this->actingAs($this->admin)->post('/appointments', $this->payload(['field_office_id' => $this->office->id, 'received_by_imis_id' => '9']))
            ->assertSessionHasErrors('received_by_imis_id');
        // Ana P. Reyes (7) works in Samar, not in the Field Office user's own office.
        $this->actingAs($this->fo)->post('/appointments', $this->payload(['received_by_imis_id' => '7']))
            ->assertSessionHasErrors('received_by_imis_id');
        $this->assertSame(0, Appointment::count());
    }

    public function test_received_by_can_be_cleared_and_a_saved_one_is_kept(): void
    {
        $this->actingAs($this->fo)->post('/appointments', $this->payload(['received_by_imis_id' => '1']));
        $a = Appointment::sole();

        $this->actingAs($this->fo)->put("/appointments/{$a->id}", $this->payload(['received_by_imis_id' => 'keep']))->assertRedirect('/appointments');
        $this->assertSame('Ryan Jose M. Estoque', $a->fresh()->received_by);

        $this->actingAs($this->fo)->put("/appointments/{$a->id}", $this->payload(['received_by_imis_id' => '']))->assertRedirect('/appointments');
        $this->assertNull($a->fresh()->received_by);
        $this->assertNull($a->fresh()->received_by_imis_id);
    }

    public function test_the_create_form_offers_the_same_people_and_starts_with_the_user_encoding_it(): void
    {
        $user = User::factory()->fieldOffice($this->office->id)->create(['imis_user_id' => 3]);

        $this->actingAs($user)->get('/appointments/create')->assertInertia(fn ($page) => $page
            ->where('defaults.received_by_imis_id', 3)
            ->has('actionOfficers.groups', 1));
    }
}
