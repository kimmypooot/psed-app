<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\FieldOffice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardDrilldownTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_list_can_be_limited_to_what_was_acted_on_in_a_period(): void
    {
        $office = FieldOffice::factory()->create();
        $admin = User::factory()->admin()->create();
        $base = ['field_office_id' => $office->id, 'date_received' => '2026-03-01', 'disapproval_reason' => 'x'];

        $inside = Appointment::factory()->create($base + ['last_name' => 'Inside', 'csc_action' => 'Approved', 'action_date' => '2026-03-15']);
        Appointment::factory()->create($base + ['last_name' => 'Before', 'csc_action' => 'Approved', 'action_date' => '2026-02-15']);
        Appointment::factory()->create($base + ['last_name' => 'Waiting', 'csc_action' => 'Pending', 'action_date' => null]);

        $rows = $this->actingAs($admin)->get("/appointments?office={$office->id}&status=acted&acted_from=2026-03-01&acted_to=2026-03-31")
            ->assertOk()->viewData('page')['props']['appointments']['data'];

        $this->assertSame([$inside->full_name], array_column($rows, 'full_name'));
    }
}
