<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\FieldOffice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpecialGroupReportTest extends TestCase
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

    private function make(string $received, array $flags = [], ?FieldOffice $office = null): void
    {
        Appointment::factory()->create(array_merge(
            ['field_office_id' => ($office ?? $this->office)->id, 'date_received' => $received],
            $flags,
        ));
    }

    private function props(string $query): array
    {
        return $this->actingAs($this->admin)->get('/statistics/special-groups'.$query)->assertOk()->viewData('page')['props'];
    }

    public function test_only_administrators_can_open_it(): void
    {
        $fo = User::factory()->fieldOffice($this->office->id)->create();

        $this->actingAs($fo)->get('/statistics/special-groups')->assertForbidden();
        $this->actingAs($fo)->get('/statistics/special-groups/export')->assertForbidden();
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get('/statistics/special-groups')->assertRedirect();
    }

    public function test_counts_each_group_by_month(): void
    {
        $this->make('2026-01-10', ['is_pwd' => true]);
        $this->make('2026-01-20', ['is_pwd' => true, 'is_solo_parent' => true]);
        $this->make('2026-03-05', ['is_indigenous' => true]);
        $this->make('2026-03-06');
        $this->make('2025-12-31', ['is_pwd' => true]); // another year

        $props = $this->props('?period=monthly&year=2026');
        $rows = collect($props['rows'])->keyBy('key');

        $this->assertCount(12, $rows);
        $this->assertSame(['total' => 2, 'pwd' => 2, 'indigenous' => 0, 'solo_parent' => 1], collect($rows['2026-01'])->only(['total', 'pwd', 'indigenous', 'solo_parent'])->all());
        $this->assertSame(0, $rows['2026-02']['total']);
        $this->assertSame(1, $rows['2026-03']['indigenous']);
        $this->assertSame(['total' => 4, 'pwd' => 2, 'indigenous' => 1, 'solo_parent' => 1], $props['totals']);
    }

    public function test_quarterly_semestral_and_yearly_groupings(): void
    {
        $this->make('2026-03-31', ['is_pwd' => true]);
        $this->make('2026-04-01', ['is_pwd' => true]);
        $this->make('2026-09-30', ['is_solo_parent' => true]);
        $this->make('2025-07-01', ['is_indigenous' => true]);

        $quarters = collect($this->props('?period=quarterly&year=2026')['rows'])->keyBy('key');
        $this->assertSame([1, 1, 1, 0], [$quarters['2026-Q1']['pwd'], $quarters['2026-Q2']['pwd'], $quarters['2026-Q3']['total'], $quarters['2026-Q4']['total']]);

        $semesters = collect($this->props('?period=semestral&year=2026')['rows'])->keyBy('key');
        $this->assertSame([2, 1], [$semesters['2026-S1']['pwd'], $semesters['2026-S2']['solo_parent']]);

        $years = collect($this->props('?period=yearly')['rows'])->keyBy('key');
        $this->assertEquals(['2025', '2026'], $years->keys()->all());
        $this->assertSame(1, $years['2025']['indigenous']);
        $this->assertSame(3, $years['2026']['total']);
    }

    public function test_can_be_limited_to_one_field_office(): void
    {
        $other = FieldOffice::factory()->create();
        $this->make('2026-02-02', ['is_pwd' => true]);
        $this->make('2026-02-03', ['is_pwd' => true], $other);

        $this->assertSame(2, $this->props('?year=2026')['totals']['pwd']);
        $this->assertSame(1, $this->props('?year=2026&field_office_id='.$other->id)['totals']['pwd']);
    }

    public function test_exports_csv_and_records_it(): void
    {
        $this->make('2026-01-10', ['is_pwd' => true]);

        $response = $this->actingAs($this->admin)->get('/statistics/special-groups/export?period=quarterly&year=2026');

        $response->assertOk()->assertDownload('special-groups-quarterly-2026.csv');
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Persons with Disability', $csv);
        $this->assertStringContainsString('"Q1 2026 (Jan–Mar)",1,0,0,1', $csv);
        $this->assertStringContainsString('Total,1,0,0,1', $csv);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.generate', 'user_id' => $this->admin->id]);
    }

    public function test_rejects_bad_filters(): void
    {
        $this->actingAs($this->admin)->get('/statistics/special-groups?period=weekly')->assertSessionHasErrors('period');
    }
}
