<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\FieldOffice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportDownloadTest extends TestCase
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

    private function downloadUrl(array $overrides = []): string
    {
        return '/reports/download?'.http_build_query(array_merge(['type' => 'mrap', 'month' => 10, 'year' => 2026], $overrides));
    }

    public function test_a_field_office_user_downloads_their_own_office_report_as_an_xlsx_file(): void
    {
        if (! glob(config('psed.templates_path').'/*MRAP*.xlsx')) {
            $this->markTestSkipped('MRAP template is not in storage/app/templates.');
        }

        $user = User::factory()->fieldOffice($this->office->id)->create();
        Appointment::factory()->create(['field_office_id' => $this->office->id, 'date_received' => '2026-10-05']);

        $response = $this->actingAs($user)->get($this->downloadUrl());

        $response->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertDownload('ESFO MRAP - October 2026.xlsx');
    }

    public function test_a_field_office_user_cannot_pick_another_office(): void
    {
        if (! glob(config('psed.templates_path').'/*MRAP*.xlsx')) {
            $this->markTestSkipped('MRAP template is not in storage/app/templates.');
        }

        $user = User::factory()->fieldOffice($this->office->id)->create();

        // Asking for the other office still produces their own office's report.
        $this->actingAs($user)->get($this->downloadUrl(['field_office_id' => $this->other->id]))
            ->assertDownload('ESFO MRAP - October 2026.xlsx');
    }

    public function test_psed_must_say_which_office(): void
    {
        $psed = User::factory()->admin()->create();

        $this->actingAs($psed)->get($this->downloadUrl())->assertSessionHasErrors('field_office_id');
    }

    public function test_psed_and_administrators_can_download_the_consolidated_report_or_any_one_office(): void
    {
        if (! glob(config('psed.templates_path').'/*MRAP*.xlsx')) {
            $this->markTestSkipped('MRAP template is not in storage/app/templates.');
        }

        foreach ([User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)->get($this->downloadUrl(['field_office_id' => 'all']))
                ->assertDownload('RO8 MRAP - October 2026.xlsx');
            $this->actingAs($user)->get($this->downloadUrl(['field_office_id' => $this->other->id]))
                ->assertDownload('SFO MRAP - October 2026.xlsx');
        }
    }

    public function test_a_field_office_user_cannot_get_the_consolidated_report(): void
    {
        if (! glob(config('psed.templates_path').'/*MRAP*.xlsx')) {
            $this->markTestSkipped('MRAP template is not in storage/app/templates.');
        }

        $user = User::factory()->fieldOffice($this->office->id)->create();

        // Asking for "all" still gives their own office's report.
        $this->actingAs($user)->get($this->downloadUrl(['field_office_id' => 'all']))
            ->assertDownload('ESFO MRAP - October 2026.xlsx');
    }

    public function test_the_office_choice_must_be_all_or_an_office_number(): void
    {
        $psed = User::factory()->admin()->create();

        $this->actingAs($psed)->get($this->downloadUrl(['field_office_id' => 'everything']))->assertSessionHasErrors('field_office_id');
    }

    public function test_the_choices_are_validated(): void
    {
        $user = User::factory()->fieldOffice($this->office->id)->create();

        $this->actingAs($user)->get($this->downloadUrl(['type' => 'nope']))->assertSessionHasErrors('type');
        $this->actingAs($user)->get($this->downloadUrl(['month' => 13]))->assertSessionHasErrors('month');
    }

    public function test_a_missing_template_sends_the_user_back_with_the_reason(): void
    {
        config(['psed.templates.mrap' => 'no-such-template*.xlsx']);
        $user = User::factory()->fieldOffice($this->office->id)->create();

        $this->actingAs($user)->get($this->downloadUrl())
            ->assertRedirect('/reports')
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'MRAP template was not found'));
    }

    public function test_guests_cannot_download(): void
    {
        $this->get($this->downloadUrl())->assertRedirect('/login');
    }
}
