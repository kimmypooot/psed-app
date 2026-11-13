<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\FieldOffice;
use App\Models\User;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The MOA has a Plantilla sheet and a CasualCon sheet; the appointment lists follow it. An appointment belongs to
 * one of them by its employment status (Casual and Contractual are CasualCon), the same rule the MOA report uses.
 */
class AppointmentTypesTest extends TestCase
{
    use RefreshDatabase;

    private FieldOffice $office;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->office = FieldOffice::factory()->create();
        $this->admin = User::factory()->admin()->create();

        foreach (['Permanent', 'Permanent', 'Temporary', 'Coterminous'] as $i => $status) {
            Appointment::factory()->create(['field_office_id' => $this->office->id, 'employment_status' => $status, 'last_name' => "Plantilla{$i}"]);
        }
        foreach (['Casual', 'Casual', 'Contractual'] as $i => $status) {
            Appointment::factory()->create(['field_office_id' => $this->office->id, 'employment_status' => $status, 'last_name' => "Casualcon{$i}"]);
        }
    }

    public function test_each_list_shows_only_its_own_sheets_appointments(): void
    {
        $this->actingAs($this->admin)->get('/appointments/plantilla')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Appointments/Index')->where('type', 'plantilla')->where('appointments.total', 4)
            ->where('appointments.data', fn ($rows) => collect($rows)->every(fn ($r) => ! in_array($r['employment_status'], ['Casual', 'Contractual'], true))));

        $this->get('/appointments/casual-contractual')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('type', 'casual-contractual')->where('appointments.total', 3)
            ->where('appointments.data', fn ($rows) => collect($rows)->every(fn ($r) => in_array($r['employment_status'], ['Casual', 'Contractual'], true))));

        $this->get('/appointments')->assertInertia(fn (Assert $page) => $page->where('type', null)->where('appointments.total', 7));
    }

    public function test_filters_search_and_office_scope_apply_within_a_list(): void
    {
        $this->actingAs($this->admin)->get('/appointments/casual-contractual?search=Casualcon1')->assertInertia(fn (Assert $page) => $page
            ->where('appointments.total', 1)->where('appointments.data.0.employment_status', 'Casual'));

        // A search that matches the other sheet's appointment finds nothing here.
        $this->get('/appointments/casual-contractual?search=Plantilla0')->assertInertia(fn (Assert $page) => $page->where('appointments.total', 0));

        // A Field Office sees only its own, on either list.
        $otherOffice = FieldOffice::factory()->create();
        Appointment::factory()->create(['field_office_id' => $otherOffice->id, 'employment_status' => 'Casual']);
        $fo = User::factory()->fieldOffice($otherOffice->id)->create();

        $this->actingAs($fo)->get('/appointments/casual-contractual')->assertInertia(fn (Assert $page) => $page->where('appointments.total', 1));
        $this->get('/appointments/plantilla')->assertInertia(fn (Assert $page) => $page->where('appointments.total', 0));
    }

    public function test_the_sidebar_has_an_appointments_group_that_is_always_open_with_a_link_for_each_sheet(): void
    {
        $items = collect(Navigation::for($this->admin));

        $group = $items->firstWhere('group', 'Appointments');
        $this->assertTrue($group['fixed'], 'always shown open: nothing to toggle');
        $this->assertSame(
            ['Plantilla' => '/appointments/plantilla', 'Casual / Contractual' => '/appointments/casual-contractual'],
            collect($group['items'])->pluck('href', 'label')->all(),
        );
        $this->assertSame('/appointments/create', $items->firstWhere('label', 'Encode Appointment')['href']);

        // Field Office staff get the same group.
        $fo = User::factory()->fieldOffice($this->office->id)->create();
        $this->assertNotNull(collect(Navigation::for($fo))->firstWhere('group', 'Appointments'));
    }

    public function test_the_scopes_agree_with_the_moa_reports_split(): void
    {
        $all = Appointment::all();
        [$casual, $plantilla] = $all->partition(fn (Appointment $a) => $a->isCasualContractual());

        $this->assertEqualsCanonicalizing($plantilla->pluck('id')->all(), Appointment::plantilla()->pluck('id')->all());
        $this->assertEqualsCanonicalizing($casual->pluck('id')->all(), Appointment::casualContractual()->pluck('id')->all());
        $this->assertSame($all->count(), Appointment::ofType(null)->count());
    }
}
