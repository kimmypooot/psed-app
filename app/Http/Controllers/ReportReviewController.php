<?php

namespace App\Http\Controllers;

use App\Enums\ReportType;
use App\Enums\SubmissionStatus;
use App\Models\AuditLog;
use App\Models\FieldOffice;
use App\Models\ReportSubmission;
use App\Services\ReportDeadlines;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * PSED, the consolidator, sees which Field Offices have submitted which report for a month, opens
 * the files, and accepts them or returns them with a reason.
 */
class ReportReviewController extends Controller
{
    public function index(Request $request, ReportDeadlines $deadlines): Response
    {
        $this->authorize('review-reports');

        [$year, $month] = ReportSubmissionController::period($request);

        $rows = ReportSubmission::with(['submitter:id,name', 'reviewer:id,name'])
            ->where('year', $year)->where('month', $month)->orderBy('id')->get();
        $latest = ReportSubmission::newestOf($rows);
        $due = collect(ReportType::cases())->mapWithKeys(fn (ReportType $t) => [$t->value => $deadlines->dueDate($t, $year, $month)]);

        $offices = FieldOffice::orderBy('name')->get(['id', 'name', 'imis_code']);

        $cells = fn (FieldOffice $o) => collect(ReportType::cases())->map(function (ReportType $type) use ($latest, $rows, $o, $due) {
            $current = $latest->get("{$o->id}:{$type->value}");

            return [
                'type' => $type->value,
                'overdue' => now()->gt($due[$type->value]) && ($current === null || $current->status === SubmissionStatus::Returned),
                'current' => $current?->present(),
                'earlier' => $rows->filter(fn (ReportSubmission $s) => $s->field_office_id === $o->id && $s->type === $type && $s->id !== $current?->id)
                    ->sortByDesc('id')->map->present()->values(),
            ];
        })->values();

        $counts = $latest->countBy(fn (ReportSubmission $s) => $s->status->value);

        return Inertia::render('Reports/Submissions', [
            'period' => ['year' => $year, 'month' => $month],
            'years' => range(now()->year, now()->year - 3),
            'types' => collect(ReportType::cases())->map(fn (ReportType $t) => ['value' => $t->value, 'label' => $t->label(), 'due' => $due[$t->value]->toDateString()])->all(),
            'offices' => $offices->map(fn (FieldOffice $o) => ['id' => $o->id, 'name' => $o->name, 'cells' => $cells($o)])->values(),
            'summary' => [
                'expected' => $offices->count() * count(ReportType::cases()),
                'received' => $latest->count(),
                'accepted' => $counts['accepted'] ?? 0,
                'returned' => $counts['returned'] ?? 0,
                'waiting' => $counts['submitted'] ?? 0,
            ],
        ]);
    }

    public function accept(Request $request, ReportSubmission $submission): RedirectResponse
    {
        $this->authorize('review-reports');
        $this->assertCurrent($submission);

        if ($submission->status !== SubmissionStatus::Submitted) {
            return back()->with('error', 'Only a report waiting for review can be accepted.');
        }

        $submission->update(['status' => SubmissionStatus::Accepted, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'review_note' => null]);

        AuditLog::record('report.accept', $submission->fieldOffice, after: $this->summary($submission));

        return back()->with('status', "{$submission->fieldOffice->name}'s {$submission->type->label()} was accepted.");
    }

    public function return(Request $request, ReportSubmission $submission): RedirectResponse
    {
        $this->authorize('review-reports');
        $this->assertCurrent($submission);

        $data = $request->validate(
            ['reason' => ['required', 'string', 'max:500']],
            ['reason.required' => 'Say what the office needs to correct.'],
        );

        if ($submission->status === SubmissionStatus::Returned) {
            return back()->with('error', 'This report was already returned.');
        }

        $submission->update(['status' => SubmissionStatus::Returned, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'review_note' => $data['reason']]);

        AuditLog::record('report.return', $submission->fieldOffice, after: $this->summary($submission) + ['reason' => $data['reason']]);

        return back()->with('status', "{$submission->fieldOffice->name}'s {$submission->type->label()} was returned for correction.");
    }

    /** Every report received for the month, in one zip: the newest file of each office and report, without those returned. */
    public function download(Request $request): BinaryFileResponse|RedirectResponse
    {
        $this->authorize('review-reports');

        [$year, $month] = ReportSubmissionController::period($request);

        $files = ReportSubmission::newestOf(
            ReportSubmission::with('fieldOffice:id,name,imis_code')->where('year', $year)->where('month', $month)->get(),
        )->reject(fn (ReportSubmission $s) => $s->status === SubmissionStatus::Returned)
            ->filter(fn (ReportSubmission $s) => Storage::disk('local')->exists($s->file_path));

        if ($files->isEmpty()) {
            return back()->with('error', 'No report has been received for this month yet.');
        }

        $name = sprintf('Field Office reports - %s %d.zip', date('F', mktime(0, 0, 0, $month, 1)), $year);
        $path = tempnam(sys_get_temp_dir(), 'reports');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);

        foreach ($files as $s) {
            $zip->addFile(Storage::disk('local')->path($s->file_path), $s->fieldOffice->name.'/'.$s->type->label().' - '.$s->file_name);
        }

        $zip->close();

        AuditLog::record('report.download_all', after: ['year' => $year, 'month' => $month, 'files' => $files->count()]);

        return response()->download($path, $name, ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }

    /** Only the newest upload of an office's report is reviewed; an older one was replaced. */
    private function assertCurrent(ReportSubmission $submission): void
    {
        $newest = ReportSubmission::where('field_office_id', $submission->field_office_id)->where('type', $submission->type->value)
            ->where('year', $submission->year)->where('month', $submission->month)->max('id');

        abort_unless($newest === $submission->id, 409, 'The office has replaced this report since.');
    }

    /** @return array<string, mixed> */
    private function summary(ReportSubmission $s): array
    {
        return ['type' => $s->type->value, 'year' => $s->year, 'month' => $s->month, 'file' => $s->file_name];
    }
}
