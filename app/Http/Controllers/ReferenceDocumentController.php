<?php

namespace App\Http\Controllers;

use App\Enums\ReferenceType;
use App\Http\Requests\ReferenceDocumentRequest;
use App\Models\AuditLog;
use App\Models\ReferenceDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The Qualification Standards page: the standards, issuances, memorandums and policies that guide the
 * processing of appointments. Everyone signed in can read and open them;
 * only PSED staff and administrators ('manage-references') change them.
 * Uploaded files live on the private disk and are served through file().
 */
class ReferenceDocumentController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::enum(ReferenceType::class)],
            'year' => ['nullable', 'integer', 'between:1900,2100'],
        ]);

        $documents = ReferenceDocument::query()
            ->when(! empty($filters['search']), function (Builder $q) use ($filters) {
                $like = '%'.$filters['search'].'%';
                $q->where(fn (Builder $w) => $w->where('title', 'like', $like)
                    ->orWhere('reference_no', 'like', $like)
                    ->orWhere('summary', 'like', $like));
            })
            ->when(! empty($filters['type']), fn (Builder $q) => $q->where('type', $filters['type']))
            ->when(! empty($filters['year']), fn (Builder $q) => $q->whereYear('issued_on', $filters['year']))
            ->orderByRaw('issued_on IS NULL')->orderByDesc('issued_on')->orderByDesc('id')
            ->paginate(10)->withQueryString();

        return Inertia::render('QualificationStandards/Index', [
            'documents' => [
                'data' => $documents->getCollection()->map(fn (ReferenceDocument $d) => $this->present($d))->values(),
                'current_page' => $documents->currentPage(),
                'last_page' => $documents->lastPage(),
                'total' => $documents->total(),
                'prev_page_url' => $documents->previousPageUrl(),
                'next_page_url' => $documents->nextPageUrl(),
            ],
            'filters' => [
                'search' => $filters['search'] ?? '',
                'type' => $filters['type'] ?? '',
                'year' => $filters['year'] ?? '',
            ],
            'types' => collect(ReferenceType::cases())->map(fn (ReferenceType $t) => ['value' => $t->value, 'label' => $t->label()])->all(),
            'years' => ReferenceDocument::whereNotNull('issued_on')->pluck('issued_on')->map->year->unique()->sortDesc()->values(),
            'canManage' => $request->user()->can('manage-references'),
        ]);
    }

    public function store(ReferenceDocumentRequest $request): RedirectResponse
    {
        $data = $request->safe()->except(['file', 'remove_file']);
        $document = ReferenceDocument::create($data + [
            ...$this->fileAttributes($request->file('file')),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        AuditLog::record('reference.create', $document, after: $document->only('title', 'type', 'reference_no'));

        return redirect()->route('standards.index')->with('status', "\"{$document->title}\" was added to the Qualification Standards.");
    }

    public function update(ReferenceDocumentRequest $request, ReferenceDocument $reference): RedirectResponse
    {
        $before = $reference->only('title', 'type', 'reference_no');
        $data = $request->safe()->except(['file', 'remove_file']);
        $oldPath = $reference->file_path;

        if ($request->hasFile('file')) {
            $data += $this->fileAttributes($request->file('file'));   // replaces the file
        } elseif ($request->boolean('remove_file')) {
            $data += ['file_path' => null, 'file_name' => null, 'file_mime' => null, 'file_size' => null];
        }

        $reference->update($data + ['updated_by' => $request->user()->id]);

        // Only once the row points at the new file is the old one removed.
        if ($oldPath !== null && $oldPath !== $reference->file_path) {
            Storage::disk('local')->delete($oldPath);
        }

        AuditLog::record('reference.update', $reference, before: $before, after: $reference->only('title', 'type', 'reference_no'));

        return redirect()->route('standards.index')->with('status', "\"{$reference->title}\" was updated.");
    }

    public function destroy(Request $request, ReferenceDocument $reference): RedirectResponse
    {
        $this->authorize('manage-references');

        AuditLog::record('reference.delete', $reference, before: $reference->only('title', 'type', 'reference_no'));

        if ($reference->hasFile()) {
            Storage::disk('local')->delete($reference->file_path);
        }
        $title = $reference->title;
        $reference->delete();

        return redirect()->route('standards.index')->with('status', "\"{$title}\" was removed from the Qualification Standards.");
    }

    /** Opens a PDF in the browser; other formats download. Signed-in staff only. */
    public function file(ReferenceDocument $reference): BinaryFileResponse
    {
        abort_unless($reference->hasFile() && Storage::disk('local')->exists($reference->file_path), 404);

        $name = $reference->file_name ?: 'reference';

        return response()->file(Storage::disk('local')->path($reference->file_path), [
            'Content-Type' => $reference->file_mime ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ])->setContentDisposition($reference->isPdf() ? 'inline' : 'attachment', $name, preg_replace('/[^\x20-\x7e]|[%"\\\\]/', '_', $name));
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string, mixed> */
    private function fileAttributes(?UploadedFile $file): array
    {
        if ($file === null) {
            return [];
        }

        return [
            'file_path' => $file->store('references', 'local'),
            'file_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'file_mime' => $file->getMimeType(),
            'file_size' => $file->getSize(),
        ];
    }

    /** @return array<string, mixed> */
    private function present(ReferenceDocument $d): array
    {
        return [
            'id' => $d->id,
            'title' => $d->title,
            'type' => $d->type->value,
            'type_label' => $d->type->label(),
            'type_tone' => $d->type->tone(),
            'reference_no' => $d->reference_no,
            'issued_on' => $d->issued_on?->toDateString(),
            'summary' => $d->summary,
            'url' => $d->url,
            'has_file' => $d->hasFile(),
            'file_name' => $d->file_name,
            'file_size' => $d->fileSizeLabel(),
            'is_pdf' => $d->isPdf(),
            'updated_at' => $d->updated_at?->toIso8601String(),
        ];
    }
}
