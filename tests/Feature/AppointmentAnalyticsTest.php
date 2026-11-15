<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\FieldOffice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppointmentAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private FieldOffice $office;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->office = FieldOffice::factory()->create();
        $this->admin = User::factory()->admin()->create();
    }

    private function make(array $attrs = []): Appointment
    {
        return Appointment::factory()->create(array_merge([
            'field_office_id' => $this->office->id,
            'date_received' => '2026-03-10',
            'csc_action' => 'Pending',
            'action_date' => null,
            'release_date' => null,
            'is_pwd' => false, 'is_indigenous' => false, 'is_solo_parent' => false,
        ], $attrs));
    }

    private function stats(string $query = '?year=2026'): array
    {
        return $this->actingAs($this->admin)->get('/statistics/appointments'.$query)->assertOk()->viewData('page')['props']['stats'];
    }

    public function test_only_administrators_can_open_it(): void
    {
        $fo = User::factory()->fieldOffice($this->office->id)->create();

        $this->actingAs($fo)->get('/statistics/appointments')->assertForbidden();
        auth()->logout();
        $this->get('/statistics/appointments')->assertRedirect();
    }

    public function test_it_counts_outcomes_and_the_approval_rate(): void
    {
        $this->make(['csc_action' => 'Approved', 'action_date' => '2026-03-14']);
        $this->make(['csc_action' => 'Validated', 'action_date' => '2026-03-12']);
        $this->make(['csc_action' => 'Disapproved', 'action_date' => '2026-03-20', 'disapproval_reason' => 'x']);
        $this->make(['csc_action' => 'Pending']);
        $this->make(['date_received' => '2025-03-10', 'csc_action' => 'Approved', 'action_date' => '2025-03-11']);

        $s = $this->stats();

        $this->assertSame(4, $s['total'], 'only the year chosen is counted');
        $this->assertSame(['approved' => 2, 'rejected' => 1, 'pending' => 1], $s['outcome']);
        $this->assertSame(66.7, $s['approval_rate']);
        $this->assertSame(3, $s['decided']);
    }

    public function test_turnaround_is_the_days_from_receipt_to_the_decision(): void
    {
        $this->make(['csc_action' => 'Approved', 'action_date' => '2026-03-14']); // 4 days
        $this->make(['csc_action' => 'Approved', 'action_date' => '2026-03-20']); // 10 days

        $t = $this->stats()['turnaround'];

        $this->assertSame(2, $t['count']);
        $this->assertSame(7.0, $t['average']);
        $this->assertSame(7.0, $t['median']);
        $this->assertSame(10, $t['slowest']);
    }

    public function test_the_trend_has_every_month_and_breakdowns_count_by_detail(): void
    {
        $this->make(['employment_status' => 'Casual', 'salary_grade' => 3, 'csc_action' => 'Approved', 'action_date' => '2026-03-12']);
        $this->make(['employment_status' => 'Permanent', 'salary_grade' => 18]);

        $s = $this->stats();

        $this->assertCount(12, $s['trend']);
        $this->assertSame(2, $s['trend'][2]['total'], 'March');
        $this->assertSame(1, $s['trend'][2]['approved']);
        $statuses = collect($s['breakdowns']['employment_status'])->pluck('total', 'label');
        $this->assertSame(1, $statuses['Casual']);
        $this->assertSame(1, $statuses['Permanent']);
        $bands = collect($s['breakdowns']['grade'])->pluck('total', 'label');
        $this->assertSame(1, $bands['SG 1–10']);
        $this->assertSame(1, $bands['SG 16–20']);
    }

    public function test_it_filters_by_office_and_type_and_can_span_all_years(): void
    {
        $other = FieldOffice::factory()->create();
        $this->make(['employment_status' => 'Casual']);
        $this->make(['employment_status' => 'Permanent']);
        $this->make(['field_office_id' => $other->id, 'date_received' => '2025-05-01']);

        $this->assertSame(1, $this->stats('?year=2026&type=casual-contractual')['total']);
        $this->assertSame(1, $this->stats('?year=2026&type=plantilla')['total']);
        $this->assertSame(2, $this->stats('?year=2026&field_office_id='.$this->office->id)['total']);
        $all = $this->stats('?year=all');
        $this->assertSame(3, $all['total']);
        $this->assertSame(['2025', '2026'], array_column($all['trend'], 'label'));
    }

    public function test_a_period_with_nothing_still_renders(): void
    {
        $s = $this->stats('?year=2020');

        $this->assertSame(0, $s['total']);
        $this->assertNull($s['approval_rate']);
        $this->assertNull($s['turnaround']['average']);
    }

    public function test_the_filters_are_validated(): void
    {
        $this->actingAs($this->admin)->get('/statistics/appointments?year=abc')->assertSessionHasErrors('year');
        $this->actingAs($this->admin)->get('/statistics/appointments?type=nope')->assertSessionHasErrors('type');
    }
}
