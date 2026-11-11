<?php

namespace Tests\Feature;

use App\Enums\HeadType;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\FieldOffice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private FieldOffice $office;

    private User $fo;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->office = FieldOffice::factory()->headedBy(HeadType::DirectorII)->create(['name' => 'Field Office-Eastern Samar', 'imis_code' => 'ESFO']);
        $this->fo = User::factory()->fieldOffice($this->office->id)->create(['name' => 'Ryan Estoque']);
        $this->admin = User::factory()->admin()->create(['name' => 'Ana Admin']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'agency' => 'DepEd Eastern Samar Division', 'date_received' => '2026-10-05',
            'last_name' => 'Reyes', 'first_name' => 'Ana', 'sex' => 'F',
            'position' => 'Administrative Aide I', 'salary_grade' => 1,
            'employment_status' => 'Permanent', 'nature_of_appointment' => 'Original',
        ], $overrides);
    }

    /** @return array<string, mixed> the entries the page was given, newest first */
    private function entries(string $query = ''): array
    {
        return $this->actingAs($this->admin)->get('/audit-trail'.$query)
            ->assertOk()->viewData('page')['props']['entries']['data'];
    }

    // ----------------------------------------------------------------- access

    public function test_only_psed_administrators_can_open_the_audit_trail(): void
    {
        $this->get('/audit-trail')->assertRedirect('/login');
        $this->actingAs($this->fo)->get('/audit-trail')->assertForbidden();
        $this->actingAs($this->admin)->get('/audit-trail')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('AuditTrail/Index')->has('groups', 8));
    }

    public function test_the_sidebar_offers_it_to_administrators_only(): void
    {
        $labels = fn (User $u) => collect($this->actingAs($u)->get('/dashboard')->viewData('page')['props']['navigation'])->pluck('label');

        $this->assertContains('Audit Trail', $labels($this->admin));
        $this->assertNotContains('Audit Trail', $labels($this->fo));
    }

    // ---------------------------------------------------------------- content

    public function test_a_change_lists_only_the_fields_that_changed_and_who_made_it(): void
    {
        $this->actingAs($this->fo)->post('/appointments', $this->payload());
        $a = Appointment::sole();
        $this->actingAs($this->fo)->put("/appointments/{$a->id}", $this->payload(['position' => 'Clerk II', 'salary_grade' => 27]));

        $update = collect($this->entries())->firstWhere('action', 'appointment.update');

        $this->assertSame('Ryan Estoque', $update['user']);
        $this->assertSame('Changed an appointment', $update['action_label']);
        $this->assertSame('Appointment: Reyes, Ana', $update['subject']);

        $changes = collect($update['changes'])->keyBy('field');
        $this->assertSame('Administrative Aide I', $changes['Position']['from']);
        $this->assertSame('Clerk II', $changes['Position']['to']);
        $this->assertSame('1', $changes['Salary Grade']['from']);
        $this->assertSame('27', $changes['Salary Grade']['to']);
        $this->assertSame('field_office', $changes['Processed By']['from'], 'the re-routing is visible too');
        $this->assertSame('psed', $changes['Processed By']['to']);
        $this->assertArrayNotHasKey('Last Name', $changes->all(), 'unchanged fields are left out');
    }

    public function test_a_create_lists_what_was_entered_and_a_delete_what_was_removed(): void
    {
        $this->actingAs($this->fo)->post('/appointments', $this->payload());
        $a = Appointment::sole();

        $create = collect($this->entries())->firstWhere('action', 'appointment.create');
        $fields = collect($create['changes'])->pluck('to', 'field');
        $this->assertSame('Reyes', $fields['Last Name']);
        $this->assertSame('2026-10-05', $fields['Date Received'], 'dates without the midnight');
        $this->assertNull(collect($create['changes'])->firstWhere('field', 'Last Name')['from']);

        $this->actingAs($this->admin)->delete("/appointments/{$a->id}");

        $delete = collect($this->entries())->firstWhere('action', 'appointment.delete');
        $this->assertSame('Appointment: Reyes, Ana', $delete['subject'], 'a deleted record is still identifiable');
        $this->assertSame('Reyes', collect($delete['changes'])->firstWhere('field', 'Last Name')['from']);
    }

    public function test_reports_and_other_events_read_clearly(): void
    {
        AuditLog::create(['user_id' => $this->admin->id, 'action' => 'report.generate', 'after' => ['type' => 'mrap', 'year' => 2026, 'month' => 10, 'office' => 'ESFO']]);
        AuditLog::create(['user_id' => $this->fo->id, 'action' => 'login', 'after' => ['method' => 'imis']]);

        $entries = collect($this->entries());

        $this->assertSame('MRAP · October 2026 · ESFO', $entries->firstWhere('action', 'report.generate')['subject']);
        $this->assertSame('Signed in', $entries->firstWhere('action', 'login')['action_label']);
    }

    // ---------------------------------------------------------------- filters

    public function test_it_can_be_filtered_by_kind_person_date_and_record(): void
    {
        $this->actingAs($this->fo)->post('/appointments', $this->payload());
        $a = Appointment::sole();
        $this->actingAs($this->fo)->put("/appointments/{$a->id}", $this->payload(['position' => 'Clerk II']));
        AuditLog::create(['user_id' => $this->admin->id, 'action' => 'login', 'after' => ['method' => 'imis']]);
        AuditLog::create(['user_id' => $this->admin->id, 'action' => 'report.generate', 'after' => ['type' => 'moa', 'year' => 2026, 'month' => 10, 'office' => 'all']]);

        $total = fn (string $q) => $this->actingAs($this->admin)->get('/audit-trail?'.$q)->viewData('page')['props']['entries']['total'];

        $this->assertSame(4, $total(''));
        $this->assertSame(2, $total('group=appointment'));
        $this->assertSame(1, $total('group=auth'));
        $this->assertSame(1, $total('group=report'));
        $this->assertSame(2, $total('search=Estoque'), 'by the person\'s name');
        $this->assertSame(2, $total('search=Admin'));
        $this->assertSame(0, $total('date_to=2020-01-01'));
        $this->assertSame(4, $total('date_from=2020-01-01&date_to=2099-12-31'));
        $this->assertSame(2, $total("subject=appointment:{$a->id}"), 'the history of one appointment');
        $this->assertSame(0, $total('subject=appointment:999999'));
    }

    public function test_a_bad_filter_is_refused(): void
    {
        $this->actingAs($this->admin)->get('/audit-trail?subject=users:1')->assertSessionHasErrors('subject');
        $this->actingAs($this->admin)->get('/audit-trail?group=nonsense')->assertSessionHasErrors('group');
    }

    public function test_the_appointment_view_offers_the_history_to_administrators_only(): void
    {
        $a = Appointment::factory()->create(['field_office_id' => $this->office->id]);

        $this->actingAs($this->admin)->getJson("/appointments/{$a->id}/details")
            ->assertJsonPath('history_url', "/audit-trail?subject=appointment:{$a->id}");
        $this->actingAs($this->fo)->getJson("/appointments/{$a->id}/details")->assertJsonPath('history_url', null);
    }
}
