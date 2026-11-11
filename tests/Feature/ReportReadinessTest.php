<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\FieldOffice;
use App\Models\User;
use App\Services\ReportReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportReadinessTest extends TestCase
{
    use RefreshDatabase;

    private FieldOffice $office;

    private FieldOffice $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->office = FieldOffice::factory()->create(['name' => 'Field Office-Eastern Samar', 'imis_code' => 'ESFO']);
        $this->other = FieldOffice::factory()->create(['name' => 'Field Office-Samar', 'imis_code' => 'SFO']);
    }

    private function make(array $state = [], ?FieldOffice $office = null): Appointment
    {
        return Appointment::factory()->create($state + ['field_office_id' => ($office ?? $this->office)->id, 'date_received' => '2026-10-05']);
    }

    private function check(?FieldOffice $office = null): array
    {
        return (new ReportReadiness)->check($office ?? $this->office, 2026, 10);
    }

    /** The messages found for the one appointment, as "severity: message". */
    private function problemsOf(Appointment $a, ?FieldOffice $office = null): array
    {
        $issue = collect($this->check($office)['issues'])->firstWhere('id', $a->id);

        return $issue ? collect($issue['problems'])->map(fn ($p) => "{$p['severity']}: {$p['message']}")->all() : [];
    }

    public function test_a_complete_appointment_has_nothing_to_fix(): void
    {
        $a = $this->make();

        $result = $this->check();

        $this->assertSame(1, $result['checked']);
        $this->assertSame([], $result['issues']);
        $this->assertSame([], $this->problemsOf($a));
    }

    public function test_an_action_without_a_date_is_an_error_because_no_report_counts_it(): void
    {
        $a = $this->make(['csc_action' => 'Approved', 'action_date' => null, 'action_officer' => 'Rey Uy', 'release_date' => '2026-10-20']);

        $this->assertContains('error: It was acted upon but has no date of action, so no report counts it as acted.', $this->problemsOf($a));
    }

    public function test_an_action_dated_before_the_appointment_was_received_is_an_error(): void
    {
        $a = $this->make(['csc_action' => 'Approved', 'action_date' => '2026-10-02', 'action_officer' => 'Rey Uy', 'release_date' => '2026-10-20']);

        $this->assertContains('error: The date of action is before the date received.', $this->problemsOf($a));
    }

    public function test_a_disapproval_needs_its_reason(): void
    {
        $a = $this->make(['csc_action' => 'Disapproved', 'action_date' => '2026-10-10', 'action_officer' => 'Rey Uy', 'release_date' => '2026-10-12', 'disapproval_reason' => null]);

        $this->assertContains('error: It was disapproved or invalidated but no reason is given.', $this->problemsOf($a));
    }

    public function test_a_first_time_entrant_needs_an_eligibility(): void
    {
        $bad = $this->make(['is_first_time_entrant' => true, 'eligibility_type' => null]);
        $na = $this->make(['is_first_time_entrant' => true, 'eligibility_type' => 'N/A']);
        $good = Appointment::factory()->firstTime()->create(['field_office_id' => $this->office->id, 'date_received' => '2026-10-05']);

        $this->assertNotSame([], array_filter($this->problemsOf($bad), fn ($m) => str_contains($m, 'first-time entrant')));
        $this->assertNotSame([], array_filter($this->problemsOf($na), fn ($m) => str_contains($m, 'first-time entrant')));
        $this->assertSame([], array_filter($this->problemsOf($good), fn ($m) => str_contains($m, 'first-time entrant')));
    }

    public function test_pending_with_a_date_of_action_is_contradictory(): void
    {
        $a = $this->make(['csc_action' => 'Pending', 'action_date' => '2026-10-10', 'pending_reason' => 'Waiting for a document.']);

        $this->assertContains('error: It is marked Pending but has a date of action, so it is counted as unacted.', $this->problemsOf($a));
    }

    public function test_missing_details_that_would_print_as_na_are_warnings(): void
    {
        $a = $this->make([
            'csc_action' => 'Approved', 'action_date' => '2026-10-10', 'action_officer' => null, 'release_date' => null,
            'sector' => null, 'level' => null, 'received_by' => null, 'date_issued' => null,
        ]);

        $problems = $this->problemsOf($a);

        foreach (['No action officer.', 'No date of release yet.', 'No sector.', 'No level (1st / 2nd).',
            'No date of issuance or of effectivity.', 'No time received or no one recorded as having received it.'] as $message) {
            $this->assertContains("warning: {$message}", $problems);
        }
        $this->assertNotContains('error: It was acted upon but has no date of action, so no report counts it as acted.', $problems);
    }

    public function test_pending_without_a_reason_and_an_eligibility_without_dates_are_warnings(): void
    {
        $pending = $this->make(['csc_action' => 'Pending', 'pending_reason' => null]);
        $elig = $this->make(['eligibility_type' => 'CS Professional', 'eligibility_issued_date' => null, 'eligibility_exam_date' => null]);

        $this->assertContains('warning: Pending with no reason given.', $this->problemsOf($pending));
        $this->assertContains('warning: The eligibility has neither a date of issuance nor a date of exam.', $this->problemsOf($elig));
    }

    public function test_each_finding_says_which_reports_it_touches(): void
    {
        $this->make(['sector' => null]);

        $problem = $this->check()['issues'][0]['problems'][0];

        $this->assertSame(['MOA'], $problem['affects']);
    }

    public function test_only_the_months_appointments_are_checked_and_errors_come_first(): void
    {
        $this->make(['sector' => null, 'last_name' => 'Warn']);                                             // warning only
        $this->make(['csc_action' => 'Approved', 'action_date' => null, 'action_officer' => 'X', 'release_date' => '2026-10-20', 'last_name' => 'Err']); // error
        $this->make(['date_received' => '2026-09-01', 'sector' => null]);                                   // another month: ignored
        // Received in September but acted on in October: it is in October's MOA, so it is checked.
        $this->make(['date_received' => '2026-09-20', 'csc_action' => 'Approved', 'action_date' => '2026-10-03', 'action_officer' => null, 'release_date' => '2026-10-05', 'last_name' => 'Carry']);

        $result = $this->check();

        $this->assertSame(3, $result['checked']);
        $this->assertSame(1, $result['with_errors']);
        $this->assertSame(2, $result['with_warnings']);
        $this->assertStringStartsWith('Err', $result['issues'][0]['name'], 'an appointment with an error is listed first');
    }

    public function test_it_looks_only_at_the_chosen_office_or_at_all_of_them(): void
    {
        $this->make(['sector' => null], $this->office);
        $this->make(['sector' => null], $this->other);

        $this->assertCount(1, $this->check($this->office)['issues']);
        $this->assertCount(2, (new ReportReadiness)->check(null, 2026, 10)['issues'], 'the consolidated check covers every office');
    }

    public function test_a_long_list_is_cut_off_but_counted_in_full(): void
    {
        foreach (range(1, 5) as $i) {
            $this->make(['sector' => null]);
        }

        $result = (new ReportReadiness)->check($this->office, 2026, 10, limit: 2);

        $this->assertCount(2, $result['issues']);
        $this->assertSame(2, $result['shown']);
        $this->assertSame(5, $result['with_warnings']);
    }

    // --------------------------------------------------------------- endpoint

    public function test_the_endpoint_scopes_a_field_office_user_to_their_own_office(): void
    {
        $this->make(['sector' => null, 'last_name' => 'Mine'], $this->office);
        $this->make(['sector' => null, 'last_name' => 'Theirs'], $this->other);
        $fo = User::factory()->fieldOffice($this->office->id)->create();

        // Even asking for the other office (or "all") gives only their own.
        foreach ([$this->other->id, 'all'] as $ask) {
            $json = $this->actingAs($fo)->getJson('/reports/check?month=10&year=2026&field_office_id='.$ask)->assertOk()->json();
            $this->assertSame(1, $json['checked']);
            $this->assertStringStartsWith('Mine', $json['issues'][0]['name']);
        }
    }

    public function test_administrators_choose_an_office_or_all(): void
    {
        $this->make(['sector' => null], $this->office);
        $this->make(['sector' => null], $this->other);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->getJson('/reports/check?month=10&year=2026&field_office_id=all')->assertOk()->assertJsonPath('checked', 2);
        $this->actingAs($admin)->getJson('/reports/check?month=10&year=2026&field_office_id='.$this->other->id)->assertOk()->assertJsonPath('checked', 1);
        $this->actingAs($admin)->getJson('/reports/check?month=10&year=2026')->assertStatus(422);
        $this->actingAs($admin)->getJson('/reports/check?month=13&year=2026&field_office_id=all')->assertStatus(422);
    }

    public function test_guests_cannot_run_the_check(): void
    {
        $this->getJson('/reports/check?month=10&year=2026&field_office_id=all')->assertUnauthorized();
    }
}
