<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\FieldOffice;
use App\Models\ReferenceValue;
use App\Models\User;
use App\Services\AgencyList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AgencySyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_ties_agencies_to_the_office_of_their_jurisdiction_and_reports_what_changed(): void
    {
        $es = FieldOffice::factory()->create(['name' => 'Field Office-Eastern Samar']);
        $leyte = FieldOffice::factory()->create(['name' => 'Field Office-Leyte I']);
        $list = app(AgencyList::class);

        $first = $list->sync($list->normalize([
            ['name' => 'City Government of Borongan', 'sector' => 'LGU-City', 'field_office' => 'CSC Field Office - Eastern Samar'],
            ['name' => '  City  Government of Borongan', 'sector' => 'LGU-City', 'field_office' => 'CSC Field Office - Eastern Samar'],
            ['name' => 'Tacloban City Water District', 'sector' => 'GOCC-Water District', 'field_office' => 'CSC Field Office - Leyte I'],
            ['name' => 'Old Agency', 'sector' => 'NGA', 'field_office' => 'CSC Field Office - Leyte I'],
        ]));

        $this->assertSame(3, $first['added']);
        $this->assertSame($es->id, ReferenceValue::where('type', 'agency')->where('value', 'City Government of Borongan')->value('field_office_id'));
        $this->assertSame(['Old Agency', 'Tacloban City Water District'], app(\App\Services\LookupService::class)->agenciesFor($leyte->id));

        Appointment::factory()->create(['agency' => 'Old Agency', 'field_office_id' => $leyte->id]);

        // The directory moves one agency, drops another and adds one with an unknown office.
        $second = $list->sync($list->normalize([
            ['name' => 'City Government of Borongan', 'sector' => 'LGU-City', 'field_office' => 'CSC Field Office - Leyte I'],
            ['name' => 'Tacloban City Water District', 'sector' => 'GOCC-Water District', 'field_office' => 'CSC Field Office - Leyte I'],
            ['name' => 'Nowhere Agency', 'sector' => 'NGA', 'field_office' => 'CSC Field Office - Atlantis'],
        ]));

        $this->assertSame(1, $second['added']);
        $this->assertSame(1, $second['moved']);
        $this->assertSame(1, $second['removed']);
        $this->assertSame(['Nowhere Agency'], $second['no_office']);
        $this->assertSame(['Old Agency' => 1], $second['orphaned']->all());
        $this->assertNull(ReferenceValue::where('type', 'agency')->where('value', 'Old Agency')->first());
    }

    public function test_dry_run_changes_nothing(): void
    {
        FieldOffice::factory()->create(['name' => 'Field Office-Samar']);
        $list = app(AgencyList::class);

        $result = $list->sync([['name' => 'Samar State University', 'sector' => 'SUC', 'field_office' => 'CSC Field Office - Samar']], dryRun: true);

        $this->assertSame(1, $result['added']);
        $this->assertSame(0, ReferenceValue::where('type', 'agency')->count());
    }

    // ---------------------------------------------------------- admin page

    private function directoryReturns(array $rows): void
    {
        $this->app->instance(AgencyList::class, Mockery::mock(AgencyList::class)->makePartial()
            ->shouldReceive('fromDirectory')->andReturn((new AgencyList)->normalize($rows))
            ->shouldReceive('saveJson')->andReturnNull()
            ->getMock());
    }

    public function test_only_psed_administrators_open_the_agencies_page_or_sync(): void
    {
        $office = FieldOffice::factory()->create(['name' => 'Field Office-Samar']);
        $fo = User::factory()->fieldOffice($office->id)->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($fo)->get('/settings/agencies')->assertForbidden();
        $this->actingAs($fo)->post('/settings/agencies/sync')->assertForbidden();
        $this->actingAs($admin)->get('/settings/agencies')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Settings/Agencies')->where('total', 0)->where('last_sync', null));
    }

    public function test_check_changes_nothing_and_sync_applies_and_is_audited(): void
    {
        FieldOffice::factory()->create(['name' => 'Field Office-Samar']);
        $admin = User::factory()->admin()->create();
        $this->directoryReturns([['name' => 'Samar State University', 'sector' => 'SUC', 'field_office' => 'CSC Field Office - Samar']]);

        $this->actingAs($admin)->post('/settings/agencies/sync', ['dry_run' => true])
            ->assertSessionHas('agency_sync', fn ($r) => $r['dry_run'] === true && $r['added'] === 1);
        $this->assertSame(0, ReferenceValue::where('type', 'agency')->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'agency.sync']);

        $this->actingAs($admin)->post('/settings/agencies/sync')
            ->assertSessionHas('agency_sync', fn ($r) => $r['dry_run'] === false && $r['added'] === 1);
        $this->assertSame(1, ReferenceValue::where('type', 'agency')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'agency.sync', 'user_id' => $admin->id]);

        $this->actingAs($admin)->get('/settings/agencies')
            ->assertInertia(fn ($page) => $page->where('total', 1)->where('last_sync.summary.added', 1));
    }

    public function test_the_page_lists_the_agencies_of_each_jurisdiction(): void
    {
        $samar = FieldOffice::factory()->create(['name' => 'Field Office-Samar']);
        $leyte = FieldOffice::factory()->create(['name' => 'Field Office-Leyte I']);
        $admin = User::factory()->admin()->create();
        $this->directoryReturns([
            ['name' => 'Samar State University', 'sector' => 'SUC', 'field_office' => 'CSC Field Office - Samar'],
            ['name' => 'Tacloban City Water District', 'sector' => 'GOCC-Water District', 'field_office' => 'CSC Field Office - Leyte I'],
            ['name' => 'Nowhere Agency', 'sector' => 'NGA', 'field_office' => 'CSC Field Office - Atlantis'],
        ]);
        $this->actingAs($admin)->post('/settings/agencies/sync');

        $this->actingAs($admin)->get('/settings/agencies')->assertInertia(fn ($page) => $page
            ->where('offices.0.name', 'Field Office-Leyte I')
            ->where('offices.0.list.0.name', 'Tacloban City Water District')
            ->where('offices.1.name', 'Field Office-Samar')
            ->where('offices.1.agencies', 1)
            ->where('unassigned', ['Nowhere Agency']));
    }

    public function test_an_unreachable_directory_is_reported_and_nothing_changes(): void
    {
        $admin = User::factory()->admin()->create();
        $this->app->instance(AgencyList::class, Mockery::mock(AgencyList::class)->makePartial()
            ->shouldReceive('fromDirectory')->andThrow(new RuntimeException('Could not read the PSED directory'))->getMock());

        $this->actingAs($admin)->post('/settings/agencies/sync')->assertSessionHas('error', 'Could not read the PSED directory');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'agency.sync']);
    }

    // ---------------------------------------------------------- rename

    public function test_renaming_an_agency_renames_it_in_the_directory_and_on_its_appointments(): void
    {
        $office = FieldOffice::factory()->create(['name' => 'Field Office-Samar']);
        $admin = User::factory()->admin()->create();
        $this->directoryReturns([['name' => 'Samar State Univ', 'sector' => 'SUC', 'field_office' => 'CSC Field Office - Samar']]);
        $this->actingAs($admin)->post('/settings/agencies/sync');
        $agency = ReferenceValue::where('value', 'Samar State Univ')->sole();
        Appointment::factory()->count(2)->create(['agency' => 'Samar State Univ', 'field_office_id' => $office->id]);

        $this->app->instance(AgencyList::class, Mockery::mock(AgencyList::class)->makePartial()
            ->shouldReceive('renameInDirectory')->once()->with('Samar State Univ', 'Samar State University')->andReturn(1)
            ->shouldReceive('saveJson')->andReturnNull()
            ->getMock());

        $this->actingAs($admin)->put("/settings/agencies/{$agency->id}", ['name' => '  Samar State  University '])->assertSessionHasNoErrors();

        $this->assertSame('Samar State University', $agency->fresh()->value);
        $this->assertSame(2, Appointment::where('agency', 'Samar State University')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'agency.rename', 'user_id' => $admin->id]);
    }

    public function test_a_rename_the_directory_does_not_know_changes_nothing(): void
    {
        $office = FieldOffice::factory()->create(['name' => 'Field Office-Samar']);
        $admin = User::factory()->admin()->create();
        $agency = ReferenceValue::create(['type' => 'agency', 'value' => 'Samar State Univ', 'field_office_id' => $office->id]);
        Appointment::factory()->create(['agency' => 'Samar State Univ', 'field_office_id' => $office->id]);

        $this->app->instance(AgencyList::class, Mockery::mock(AgencyList::class)->makePartial()
            ->shouldReceive('renameInDirectory')->andReturn(0)->getMock());

        $this->actingAs($admin)->put("/settings/agencies/{$agency->id}", ['name' => 'Samar State University'])->assertSessionHasErrors('name');

        $this->assertSame('Samar State Univ', $agency->fresh()->value);
        $this->assertSame(1, Appointment::where('agency', 'Samar State Univ')->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'agency.rename']);
    }

    public function test_a_name_another_agency_has_is_refused_and_only_administrators_rename(): void
    {
        $office = FieldOffice::factory()->create(['name' => 'Field Office-Samar']);
        $admin = User::factory()->admin()->create();
        $fo = User::factory()->fieldOffice($office->id)->create();
        $one = ReferenceValue::create(['type' => 'agency', 'value' => 'Agency One', 'field_office_id' => $office->id]);
        ReferenceValue::create(['type' => 'agency', 'value' => 'Agency Two', 'field_office_id' => $office->id]);

        $this->actingAs($fo)->put("/settings/agencies/{$one->id}", ['name' => 'Agency Three'])->assertForbidden();
        $this->actingAs($admin)->put("/settings/agencies/{$one->id}", ['name' => 'Agency Two'])->assertSessionHasErrors('name');
        $this->actingAs($admin)->put("/settings/agencies/{$one->id}", ['name' => ''])->assertSessionHasErrors('name');
    }
}
