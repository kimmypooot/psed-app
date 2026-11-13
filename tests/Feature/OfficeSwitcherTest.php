<?php

namespace Tests\Feature;

use App\Http\Middleware\ApplyActiveOffice;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\FieldOffice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A Director II who heads several Field Offices works as one at a time: the choice
 * is theirs to make but only among the offices IMIS gave them, and every list, policy
 * and form then behaves exactly as for an employee of that office.
 */
class OfficeSwitcherTest extends TestCase
{
    use RefreshDatabase;

    private FieldOffice $home;

    private FieldOffice $second;

    private FieldOffice $stranger;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = FieldOffice::factory()->create(['name' => 'Leyte FO']);
        $this->second = FieldOffice::factory()->create(['name' => 'Samar FO']);
        $this->stranger = FieldOffice::factory()->create(['name' => 'Biliran FO']);

        $this->director = User::factory()->fieldOffice($this->home->id)->create();
        $this->director->extraOffices()->attach($this->second->id);
    }

    public function test_a_director_must_choose_an_office_before_working(): void
    {
        $this->actingAs($this->director)->get('/appointments')->assertRedirect(route('office.choose'));

        $this->actingAs($this->director)->get('/office')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/ChooseOffice')->has('auth.user.offices', 2));
    }

    public function test_switching_scopes_the_list_to_the_chosen_office(): void
    {
        Appointment::factory()->create(['field_office_id' => $this->home->id, 'last_name' => 'Homeperson']);
        Appointment::factory()->create(['field_office_id' => $this->second->id, 'last_name' => 'Secondperson']);
        Appointment::factory()->create(['field_office_id' => $this->stranger->id, 'last_name' => 'Strangerperson']);

        $this->actingAs($this->director)->post('/office', ['office_id' => $this->second->id])->assertRedirect(route('dashboard'));

        $this->get('/appointments')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('appointments.total', 1)
            ->where('appointments.data.0.full_name', fn ($name) => str_contains($name, 'Secondperson'))
            ->where('auth.user.office_id', $this->second->id));

        $this->post('/office', ['office_id' => $this->home->id]);
        $this->get('/appointments')->assertInertia(fn (Assert $page) => $page
            ->where('appointments.data.0.full_name', fn ($name) => str_contains($name, 'Homeperson')));
    }

    public function test_an_office_that_was_not_given_by_imis_is_refused(): void
    {
        $this->actingAs($this->director)->post('/office', ['office_id' => $this->stranger->id])->assertForbidden();

        $this->get('/appointments')->assertRedirect(route('office.choose'));
    }

    public function test_the_chosen_office_is_never_saved_to_the_account(): void
    {
        $this->actingAs($this->director)->withSession([ApplyActiveOffice::SESSION_KEY => $this->second->id])->get('/dashboard')->assertOk();

        $this->assertSame($this->home->id, $this->director->fresh()->field_office_id);
    }

    public function test_a_session_choice_that_imis_no_longer_allows_is_dropped(): void
    {
        $this->director->extraOffices()->detach();

        // One office left: no chooser, and the stale choice must not make them act as the other office.
        $this->actingAs($this->director)->withSession([ApplyActiveOffice::SESSION_KEY => $this->second->id])
            ->get('/dashboard')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.user.office_id', $this->home->id)->where('auth.user.offices', []));
    }

    public function test_a_record_of_a_sibling_office_cannot_be_opened_as_the_other_office(): void
    {
        $theirs = Appointment::factory()->create(['field_office_id' => $this->second->id]);

        $this->actingAs($this->director)->withSession([ApplyActiveOffice::SESSION_KEY => $this->home->id])
            ->get("/appointments/{$theirs->id}/details")->assertForbidden();

        $this->post('/office', ['office_id' => $this->second->id]);
        $this->get("/appointments/{$theirs->id}/details")->assertOk();
    }

    public function test_switching_is_audited_and_everyone_else_has_no_switcher(): void
    {
        $this->actingAs($this->director)->post('/office', ['office_id' => $this->second->id]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'office.switched', 'user_id' => $this->director->id]);
        $this->assertSame(1, AuditLog::where('action', 'office.switched')->count());

        $single = User::factory()->fieldOffice($this->stranger->id)->create();
        $this->actingAs($single)->post('/office', ['office_id' => $this->second->id])->assertForbidden();
        $this->get('/office')->assertRedirect(route('dashboard'));
    }
}
