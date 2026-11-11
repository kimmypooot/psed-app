<?php

namespace Tests\Feature;

use App\Models\FieldOffice;
use App\Models\ReferenceDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReferenceDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private User $fo;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->fo = User::factory()->fieldOffice(FieldOffice::factory()->create()->id)->create();
        $this->admin = User::factory()->admin()->create();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Revised Omnibus Rules on Appointments',
            'type' => 'issuance',
            'reference_no' => 'CSC MC No. 8, s. 2025',
            'issued_on' => '2025-03-14',
            'summary' => 'Governs the preparation and submission of appointments.',
        ], $overrides);
    }

    private function pdf(string $name = 'mc-8.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 200, 'application/pdf');
    }

    // ---------------------------------------------------------------- reading

    public function test_guests_cannot_see_the_qualification_standards_or_their_files(): void
    {
        $doc = ReferenceDocument::create(['title' => 'X', 'type' => 'policy']);

        $this->get('/qualification-standards')->assertRedirect('/login');
        $this->get("/qualification-standards/{$doc->id}/file")->assertRedirect('/login');
    }

    public function test_every_role_can_read_the_qualification_standards_but_only_psed_is_told_it_can_manage(): void
    {
        ReferenceDocument::create(['title' => 'A policy', 'type' => 'policy']);

        foreach ([[$this->fo, false], [$this->admin, true]] as [$user, $canManage]) {
            $this->actingAs($user)->get('/qualification-standards')->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('QualificationStandards/Index')
                    ->has('documents.data', 1)
                    ->where('canManage', $canManage));
        }
    }

    public function test_the_list_can_be_searched_and_filtered_by_type_and_year(): void
    {
        ReferenceDocument::create(['title' => 'Appointment forms guide', 'type' => 'policy', 'issued_on' => '2025-02-01']);
        ReferenceDocument::create(['title' => 'Eligibility memo', 'type' => 'memorandum', 'reference_no' => 'Memo 12', 'issued_on' => '2024-06-01']);
        ReferenceDocument::create(['title' => 'Undated note', 'type' => 'other']);

        $total = fn (string $query) => $this->actingAs($this->fo)->get("/qualification-standards?{$query}")->viewData('page')['props']['documents']['total'];

        $this->assertSame(3, $total(''));
        $this->assertSame(1, $total('search=forms'));
        $this->assertSame(1, $total('search=Memo+12'), 'the reference number is searched too');
        $this->assertSame(1, $total('type=memorandum'));
        $this->assertSame(1, $total('year=2025'));
        $this->assertSame(0, $total('type=policy&year=2024'));
    }

    public function test_qualification_standard_is_a_document_type_that_can_be_added_and_filtered(): void
    {
        $this->actingAs($this->admin)->post('/qualification-standards', $this->payload([
            'title' => 'QS for Administrative Officer II', 'type' => 'qualification_standard',
        ]))->assertRedirect('/qualification-standards');
        ReferenceDocument::create(['title' => 'Some memo', 'type' => 'memorandum']);

        $doc = ReferenceDocument::where('title', 'QS for Administrative Officer II')->sole();
        $this->assertSame('Qualification Standard', $doc->type->label());

        $this->actingAs($this->fo)->get('/qualification-standards?type=qualification_standard')
            ->assertInertia(fn (Assert $page) => $page
                ->has('documents.data', 1)
                ->where('documents.data.0.type_label', 'Qualification Standard')
                ->where('types.0.value', 'qualification_standard'));
    }

    // --------------------------------------------------------------- managing

    public function test_psed_and_administrators_can_add_a_document_with_its_file(): void
    {
        foreach ([$this->admin] as $i => $user) {
            $this->actingAs($user)->post('/qualification-standards', $this->payload(['title' => "Guide {$i}", 'file' => $this->pdf("g{$i}.pdf")]))
                ->assertRedirect('/qualification-standards');

            $doc = ReferenceDocument::where('title', "Guide {$i}")->sole();
            $this->assertSame('issuance', $doc->type->value);
            $this->assertSame("g{$i}.pdf", $doc->file_name);
            $this->assertSame('application/pdf', $doc->file_mime);
            $this->assertSame($user->id, $doc->created_by);
            Storage::disk('local')->assertExists($doc->file_path);
        }

        $this->assertDatabaseHas('audit_logs', ['action' => 'reference.create']);
    }

    public function test_field_office_staff_cannot_add_change_or_remove_documents(): void
    {
        $doc = ReferenceDocument::create(['title' => 'Keep me', 'type' => 'policy']);

        $this->actingAs($this->fo)->post('/qualification-standards', $this->payload())->assertForbidden();
        $this->actingAs($this->fo)->put("/qualification-standards/{$doc->id}", $this->payload(['title' => 'Hacked']))->assertForbidden();
        $this->actingAs($this->fo)->delete("/qualification-standards/{$doc->id}")->assertForbidden();

        $this->assertSame(1, ReferenceDocument::count());
        $this->assertSame('Keep me', $doc->fresh()->title);
    }

    public function test_updating_replaces_the_file_and_deletes_the_old_one(): void
    {
        $this->actingAs($this->admin)->post('/qualification-standards', $this->payload(['file' => $this->pdf('old.pdf')]));
        $doc = ReferenceDocument::sole();
        $oldPath = $doc->file_path;

        $this->actingAs($this->admin)->post("/qualification-standards/{$doc->id}", $this->payload([
            '_method' => 'put', 'title' => 'Renamed', 'file' => $this->pdf('new.pdf'),
        ]))->assertRedirect('/qualification-standards');

        $doc->refresh();
        $this->assertSame('Renamed', $doc->title);
        $this->assertSame('new.pdf', $doc->file_name);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($doc->file_path);
    }

    public function test_the_file_can_be_removed_and_is_kept_when_the_form_leaves_it_alone(): void
    {
        $this->actingAs($this->admin)->post('/qualification-standards', $this->payload(['file' => $this->pdf()]));
        $doc = ReferenceDocument::sole();
        $path = $doc->file_path;

        // Saved again with no new file: the file stays.
        $this->actingAs($this->admin)->post("/qualification-standards/{$doc->id}", $this->payload(['_method' => 'put']));
        Storage::disk('local')->assertExists($path);

        $this->actingAs($this->admin)->post("/qualification-standards/{$doc->id}", $this->payload(['_method' => 'put', 'remove_file' => true]));
        $this->assertNull($doc->fresh()->file_path);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_deleting_a_document_removes_its_file(): void
    {
        $this->actingAs($this->admin)->post('/qualification-standards', $this->payload(['file' => $this->pdf()]));
        $doc = ReferenceDocument::sole();

        $this->actingAs($this->admin)->delete("/qualification-standards/{$doc->id}")->assertRedirect('/qualification-standards');

        $this->assertModelMissing($doc);
        Storage::disk('local')->assertMissing($doc->file_path);
    }

    public function test_uploads_and_links_are_checked(): void
    {
        $this->actingAs($this->admin);

        $this->post('/qualification-standards', $this->payload(['file' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')]))
            ->assertSessionHasErrors('file');
        $this->post('/qualification-standards', $this->payload(['file' => UploadedFile::fake()->create('huge.pdf', 25000, 'application/pdf')]))
            ->assertSessionHasErrors('file');
        $this->post('/qualification-standards', $this->payload(['url' => 'javascript:alert(1)']))->assertSessionHasErrors('url');
        $this->post('/qualification-standards', $this->payload(['url' => 'https://csc.gov.ph/issuances']))->assertSessionHasNoErrors();
        $this->post('/qualification-standards', $this->payload(['title' => '']))->assertSessionHasErrors('title');
        $this->post('/qualification-standards', $this->payload(['type' => 'rumour']))->assertSessionHasErrors('type');

        $this->assertSame(1, ReferenceDocument::count(), 'only the valid one was saved');
    }

    // ------------------------------------------------------------ opening files

    public function test_a_pdf_opens_in_the_browser_and_other_files_download(): void
    {
        $this->actingAs($this->admin)->post('/qualification-standards', $this->payload(['title' => 'PDF one', 'file' => $this->pdf('a.pdf')]));
        $this->actingAs($this->admin)->post('/qualification-standards', $this->payload(['title' => 'Word one', 'file' => UploadedFile::fake()->create('b.docx', 50, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')]));

        $pdf = ReferenceDocument::where('title', 'PDF one')->sole();
        $word = ReferenceDocument::where('title', 'Word one')->sole();

        // Field Office staff can open them.
        $this->actingAs($this->fo)->get("/qualification-standards/{$pdf->id}/file")->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString('inline', $this->actingAs($this->fo)->get("/qualification-standards/{$pdf->id}/file")->headers->get('content-disposition'));
        $this->assertStringContainsString('attachment', $this->actingAs($this->fo)->get("/qualification-standards/{$word->id}/file")->headers->get('content-disposition'));
    }

    public function test_a_document_without_a_file_has_nothing_to_open(): void
    {
        $doc = ReferenceDocument::create(['title' => 'Text only', 'type' => 'policy']);

        $this->actingAs($this->fo)->get("/qualification-standards/{$doc->id}/file")->assertNotFound();
    }
}
