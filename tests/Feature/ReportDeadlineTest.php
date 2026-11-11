<?php

namespace Tests\Feature;

use App\Enums\ReportType;
use App\Models\FieldOffice;
use App\Models\ReportDeadline;
use App\Models\User;
use App\Services\ReportDeadlines;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportDeadlineTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    public function test_without_settings_every_report_is_due_on_the_configured_day_of_the_next_month(): void
    {
        $d = app(ReportDeadlines::class);

        $this->assertSame('2026-11-05', $d->dueDate(ReportType::Ipmr, 2026, 10)->toDateString());
        $this->assertSame('2027-01-05', $d->dueDate(ReportType::Moa, 2026, 12)->toDateString());
    }

    public function test_only_psed_administrators_open_or_change_the_deadlines(): void
    {
        $office = FieldOffice::factory()->create();
        $fo = User::factory()->fieldOffice($office->id)->create();

        $this->actingAs($fo)->get('/settings/report-deadlines')->assertForbidden();
        $this->actingAs($fo)->put('/settings/report-deadlines', ['days' => ['ipmr' => 9, 'mrap' => 9, 'moa' => 9, 'absorption' => 9]])->assertForbidden();
        $this->actingAs($fo)->post('/settings/report-deadlines/months', ['type' => 'all', 'year' => 2026, 'month' => 10, 'due_date' => '2026-11-12'])->assertForbidden();

        $this->actingAs($this->admin)->get('/settings/report-deadlines')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Settings/ReportDeadlines')->has('types', 4)->where('types.0.day', 5));
        $this->assertSame(0, ReportDeadline::count());
    }

    public function test_the_usual_day_is_set_per_report(): void
    {
        $this->actingAs($this->admin)->put('/settings/report-deadlines', ['days' => ['ipmr' => 10, 'mrap' => 7, 'moa' => 5, 'absorption' => 15]])
            ->assertSessionHasNoErrors();

        $d = app(ReportDeadlines::class);
        $this->assertSame('2026-11-10', $d->dueDate(ReportType::Ipmr, 2026, 10)->toDateString());
        $this->assertSame('2026-11-15', $d->dueDate(ReportType::Absorption, 2026, 10)->toDateString());
        $this->assertDatabaseHas('audit_logs', ['action' => 'deadline.update']);

        $this->actingAs($this->admin)->put('/settings/report-deadlines', ['days' => ['ipmr' => 31, 'mrap' => 7, 'moa' => 5, 'absorption' => 15]])
            ->assertSessionHasErrors('days.ipmr');
    }

    public function test_one_month_can_have_its_own_date_for_one_report_or_all(): void
    {
        $this->actingAs($this->admin)->post('/settings/report-deadlines/months', ['type' => 'ipmr', 'year' => 2026, 'month' => 10, 'due_date' => '2026-11-12'])
            ->assertSessionHasNoErrors();

        $d = app(ReportDeadlines::class);
        $this->assertSame('2026-11-12', $d->dueDate(ReportType::Ipmr, 2026, 10)->toDateString());
        $this->assertSame('2026-11-05', $d->dueDate(ReportType::Mrap, 2026, 10)->toDateString());   // others keep the usual day
        $this->assertSame('2026-12-05', $d->dueDate(ReportType::Ipmr, 2026, 11)->toDateString());   // other months too

        $this->actingAs($this->admin)->post('/settings/report-deadlines/months', ['type' => 'all', 'year' => 2026, 'month' => 10, 'due_date' => '2026-11-20']);
        $this->assertSame(4, ReportDeadline::whereNotNull('year')->count());
        $this->assertSame('2026-11-20', app(ReportDeadlines::class)->dueDate(ReportType::Ipmr, 2026, 10)->toDateString());
        $this->assertDatabaseHas('audit_logs', ['action' => 'deadline.set']);
    }

    public function test_a_deadline_before_the_month_ends_is_refused(): void
    {
        $this->actingAs($this->admin)->post('/settings/report-deadlines/months', ['type' => 'all', 'year' => 2026, 'month' => 10, 'due_date' => '2026-10-15'])
            ->assertSessionHasErrors('due_date');
        $this->assertSame(0, ReportDeadline::count());
    }

    public function test_removing_a_months_date_returns_it_to_the_usual_day(): void
    {
        $this->actingAs($this->admin)->post('/settings/report-deadlines/months', ['type' => 'moa', 'year' => 2026, 'month' => 10, 'due_date' => '2026-11-25']);
        $row = ReportDeadline::whereNotNull('year')->sole();

        $this->actingAs($this->admin)->delete("/settings/report-deadlines/months/{$row->id}")->assertSessionHasNoErrors();

        $this->assertSame('2026-11-05', app(ReportDeadlines::class)->dueDate(ReportType::Moa, 2026, 10)->toDateString());
        $this->assertDatabaseHas('audit_logs', ['action' => 'deadline.remove']);
    }

    public function test_the_submission_pages_show_each_reports_own_deadline(): void
    {
        $office = FieldOffice::factory()->create();
        $fo = User::factory()->fieldOffice($office->id)->create();
        $this->actingAs($this->admin)->post('/settings/report-deadlines/months', ['type' => 'ipmr', 'year' => 2026, 'month' => 10, 'due_date' => '2026-11-12']);

        $this->actingAs($fo)->get('/reports/submit?year=2026&month=10')->assertInertia(fn ($page) => $page
            ->where('reports.0.type', 'ipmr')->where('reports.0.due', '2026-11-12')->where('reports.1.due', '2026-11-05'));
        $this->actingAs($this->admin)->get('/reports/submissions?year=2026&month=10')->assertInertia(fn ($page) => $page
            ->where('types.0.due', '2026-11-12')->where('types.1.due', '2026-11-05'));
    }
}
