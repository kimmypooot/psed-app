<?php

namespace Tests\Feature;

use App\Enums\ReportType;
use App\Enums\SubmissionStatus;
use App\Models\FieldOffice;
use App\Models\ReportSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private FieldOffice $office;

    private FieldOffice $other;

    private User $fo;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->office = FieldOffice::factory()->create(['name' => 'Field Office-Samar']);
        $this->other = FieldOffice::factory()->create(['name' => 'Field Office-Leyte I']);
        $this->fo = User::factory()->fieldOffice($this->office->id)->create();
        $this->admin = User::factory()->admin()->create();
    }

    private function xlsx(string $name = 'SFO IPMR - October 2026.xlsx'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 120, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    private function payload(array $overrides = []): array
    {
        $last = now()->subMonthNoOverflow();

        return array_merge(['type' => 'ipmr', 'year' => $last->year, 'month' => $last->month, 'file' => $this->xlsx(), 'note' => 'Signed copy'], $overrides);
    }

    private function submit(array $overrides = []): ReportSubmission
    {
        $this->actingAs($this->fo)->post('/reports/submit', $this->payload($overrides))->assertSessionHasNoErrors();

        return ReportSubmission::latest('id')->firstOrFail();
    }

    public function test_only_field_office_staff_submit_and_only_psed_reviews(): void
    {
        $this->actingAs($this->admin)->get('/reports/submit')->assertForbidden();
        $this->actingAs($this->admin)->post('/reports/submit', $this->payload())->assertForbidden();
        $this->actingAs($this->fo)->get('/reports/submissions')->assertForbidden();

        $this->actingAs($this->fo)->get('/reports/submit')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Reports/Submit')->has('reports', 4)->where('office.name', 'Field Office-Samar'));
        $this->actingAs($this->admin)->get('/reports/submissions')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Reports/Submissions')->has('offices', 2)->where('summary.expected', 8));
    }

    public function test_an_office_submits_its_report_and_it_is_recorded(): void
    {
        $s = $this->submit();

        $this->assertSame($this->office->id, $s->field_office_id);
        $this->assertSame(ReportType::Ipmr, $s->type);
        $this->assertSame(SubmissionStatus::Submitted, $s->status);
        $this->assertSame($this->fo->id, $s->submitted_by);
        Storage::disk('local')->assertExists($s->file_path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.submit', 'user_id' => $this->fo->id]);
    }

    public function test_the_file_must_be_an_xlsx(): void
    {
        $this->actingAs($this->fo)->post('/reports/submit', $this->payload(['file' => UploadedFile::fake()->create('report.pdf', 50, 'application/pdf')]))
            ->assertSessionHasErrors('file');
        $this->actingAs($this->fo)->post('/reports/submit', $this->payload(['file' => null]))->assertSessionHasErrors('file');

        $this->assertSame(0, ReportSubmission::count());
    }

    public function test_a_resubmission_keeps_the_earlier_file_and_the_newest_counts(): void
    {
        $first = $this->submit();
        $second = $this->submit(['file' => $this->xlsx('again.xlsx')]);

        $this->assertSame(2, ReportSubmission::count());
        Storage::disk('local')->assertExists($first->file_path);

        $this->actingAs($this->fo)->get('/reports/submit?year='.$second->year.'&month='.$second->month)->assertInertia(fn ($page) => $page
            ->where('reports.0.current.file_name', 'again.xlsx')->has('reports.0.earlier', 1));
    }

    public function test_psed_accepts_a_report_and_an_accepted_one_is_not_replaced(): void
    {
        $s = $this->submit();

        $this->actingAs($this->admin)->post("/reports/submissions/{$s->id}/accept")->assertSessionHasNoErrors();

        $this->assertSame(SubmissionStatus::Accepted, $s->fresh()->status);
        $this->assertSame($this->admin->id, $s->fresh()->reviewed_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.accept']);

        $this->actingAs($this->fo)->post('/reports/submit', $this->payload())->assertSessionHasErrors('file');
        $this->assertSame(1, ReportSubmission::count());
    }

    public function test_a_returned_report_needs_a_reason_and_can_be_submitted_again(): void
    {
        $s = $this->submit();

        $this->actingAs($this->admin)->post("/reports/submissions/{$s->id}/return", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->admin)->post("/reports/submissions/{$s->id}/return", ['reason' => 'Wrong month in the header'])->assertSessionHasNoErrors();

        $this->assertSame(SubmissionStatus::Returned, $s->fresh()->status);
        $this->assertSame('Wrong month in the header', $s->fresh()->review_note);

        $this->actingAs($this->fo)->get('/reports/submit')->assertInertia(fn ($page) => $page
            ->has('returned', 1)->where('returned.0.review_note', 'Wrong month in the header'));

        $again = $this->submit();
        $this->assertSame(SubmissionStatus::Submitted, $again->status);
        $this->assertSame(2, ReportSubmission::count());
    }

    public function test_only_the_newest_upload_can_be_reviewed(): void
    {
        $old = $this->submit();
        $this->submit(['file' => $this->xlsx('new.xlsx')]);

        $this->actingAs($this->admin)->post("/reports/submissions/{$old->id}/accept")->assertStatus(409);
        $this->assertSame(SubmissionStatus::Submitted, $old->fresh()->status);
    }

    public function test_an_office_cannot_open_another_offices_file_but_psed_can(): void
    {
        $s = $this->submit();
        $outsider = User::factory()->fieldOffice($this->other->id)->create();

        $this->actingAs($outsider)->get("/reports/submissions/{$s->id}/file")->assertForbidden();
        $this->actingAs($this->fo)->get("/reports/submissions/{$s->id}/file")->assertOk();
        $this->actingAs($this->admin)->get("/reports/submissions/{$s->id}/file")->assertOk();
    }

    public function test_download_all_zips_what_was_received(): void
    {
        $kept = $this->submit();

        $response = $this->actingAs($this->admin)->get("/reports/submissions/download?year={$kept->year}&month={$kept->month}");
        $response->assertOk();
        $this->assertSame('application/zip', $response->headers->get('Content-Type'));

        $this->actingAs($this->admin)->get('/reports/submissions/download?year=2020&month=1')->assertSessionHas('error');
    }

    public function test_the_review_grid_shows_each_office_and_report(): void
    {
        $s = $this->submit();

        $this->actingAs($this->admin)->get("/reports/submissions?year={$s->year}&month={$s->month}")->assertInertia(fn ($page) => $page
            ->where('summary.received', 1)->where('summary.waiting', 1)
            ->where('offices.1.name', 'Field Office-Samar')
            ->where('offices.1.cells.0.current.status', 'submitted'));
    }
}
