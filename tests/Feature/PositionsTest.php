<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\FieldOffice;
use App\Models\ReferenceValue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PositionsTest extends TestCase
{
    use RefreshDatabase;

    private function position(string $name): ReferenceValue
    {
        return ReferenceValue::create(['type' => 'position', 'value' => $name]);
    }

    public function test_only_psed_administrators_manage_positions(): void
    {
        $office = FieldOffice::factory()->create();
        $fo = User::factory()->fieldOffice($office->id)->create();
        $p = $this->position('Teacher I');

        $this->actingAs($fo)->get('/settings/positions')->assertForbidden();
        $this->actingAs($fo)->post('/settings/positions', ['name' => 'Nurse I'])->assertForbidden();
        $this->actingAs($fo)->put("/settings/positions/{$p->id}", ['name' => 'Nurse I'])->assertForbidden();
        $this->actingAs($fo)->delete("/settings/positions/{$p->id}")->assertForbidden();
        $this->assertSame('Teacher I', $p->fresh()->value);
    }

    public function test_the_list_is_searched_paged_and_shows_how_many_appointments_use_each(): void
    {
        $admin = User::factory()->admin()->create();
        $office = FieldOffice::factory()->create();
        foreach (range(1, 30) as $i) {
            $this->position("Clerk {$i}");
        }
        $this->position('Teacher I');
        Appointment::factory()->count(2)->create(['position' => 'Teacher I', 'field_office_id' => $office->id]);

        $this->actingAs($admin)->get('/settings/positions')->assertInertia(fn ($page) => $page
            ->where('overall', 31)->where('positions.total', 31)->has('positions.data', 25));
        $this->actingAs($admin)->get('/settings/positions?search=teacher')->assertInertia(fn ($page) => $page
            ->where('positions.total', 1)->where('positions.data.0.name', 'Teacher I')->where('positions.data.0.appointments', 2));
    }

    public function test_a_position_is_added_once_and_the_name_is_tidied(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/settings/positions', ['name' => '  Nurse   I '])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('reference_values', ['type' => 'position', 'value' => 'Nurse I']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'position.create']);

        $this->actingAs($admin)->post('/settings/positions', ['name' => 'Nurse I'])->assertSessionHasErrors('name');
        $this->actingAs($admin)->post('/settings/positions', ['name' => ''])->assertSessionHasErrors('name');
    }

    public function test_a_rename_can_carry_the_saved_appointments_along_or_leave_them(): void
    {
        $admin = User::factory()->admin()->create();
        $office = FieldOffice::factory()->create();
        $p = $this->position('Techer I');
        Appointment::factory()->create(['position' => 'Techer I', 'field_office_id' => $office->id]);

        $this->actingAs($admin)->put("/settings/positions/{$p->id}", ['name' => 'Teacher I', 'update_appointments' => false]);
        $this->assertSame('Teacher I', $p->fresh()->value);
        $this->assertSame(1, Appointment::where('position', 'Techer I')->count());

        $q = $this->position('Nurs I');
        Appointment::factory()->create(['position' => 'Nurs I', 'field_office_id' => $office->id]);

        $this->actingAs($admin)->put("/settings/positions/{$q->id}", ['name' => 'Nurse I', 'update_appointments' => true]);
        $this->assertSame(1, Appointment::where('position', 'Nurse I')->count());
        $this->assertSame(0, Appointment::where('position', 'Nurs I')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'position.update']);
    }

    public function test_removing_a_position_leaves_the_appointments_with_their_title(): void
    {
        $admin = User::factory()->admin()->create();
        $office = FieldOffice::factory()->create();
        $p = $this->position('Teacher I');
        Appointment::factory()->create(['position' => 'Teacher I', 'field_office_id' => $office->id]);

        $this->actingAs($admin)->delete("/settings/positions/{$p->id}")->assertSessionHasNoErrors();

        $this->assertNull(ReferenceValue::find($p->id));
        $this->assertSame(1, Appointment::where('position', 'Teacher I')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'position.delete']);
    }

    public function test_only_positions_can_be_changed_through_this_page(): void
    {
        $admin = User::factory()->admin()->create();
        $agency = ReferenceValue::create(['type' => 'agency', 'value' => 'Some Agency']);

        $this->actingAs($admin)->put("/settings/positions/{$agency->id}", ['name' => 'Hijacked'])->assertNotFound();
        $this->actingAs($admin)->delete("/settings/positions/{$agency->id}")->assertNotFound();
        $this->assertSame('Some Agency', $agency->fresh()->value);
    }
}
