<?php

namespace App\Http\Controllers;

use App\Enums\ReportType;
use App\Enums\SubmissionStatus;
use App\Models\AuditLog;
use App\Models\ReportSubmission;
use App\Services\ReportDeadlines;
use App\Services\ReportReadiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A Field Office submits its generated monthly reports to PSED, which consolidates them. The office uploads
 * the file it generated (the signed copy is the real one); PSED accepts it or returns it for correction
 * (see ReportReviewController). Every upload is kept, so a resubmission does not lose the earlier file.
 */
class ReportSubmissionController extends Controller
{
    public function index(Request $request, ReportReadiness $readiness, ReportDeadlines $deadlines): Response
    {
        $this->authorize('submit-reports');

        $office = $request->user()->fieldOffice;
        [$year, $month] = self::period($request);

        $rows = ReportSubmission::with(['submitter:id,name', 'reviewer:id,name'])
            ->where('field_office_id', $office->id)
            ->orderBy('id')
            ->get();

        $inMonth = $rows->where('year', $year)->where('month', $month);
        $check = $readiness->check($office, $year, $month, 0);

        return Inertia::render('Reports/Submit', [
            'office' => $office->only('id', 'name'),
            'period' => ['year' => $year, 'month' => $month],
            'years' => range(now()->year, now()->year - 3),
            'reports' => collect(ReportType::cases())->map(function (ReportType $type) use ($inMonth, $deadlines, $year, $month) {
                $due = $deadlines->dueDate($type, $year, $month);
                $mine = $inMonth->filter(fn (ReportSubmission $s) => $s->type === $type)->values();
                $current = $mine->last();

                return [
                    'type' => $type->value,
                    'label' => $type->label(),
                    'full_name' => $type->fullName(),
                    'due' => $due->toDateString(),
                    'current' => $current?->present(),
                    'earlier' => $mine->slice(0, -1)->reverse()->map->present()->values(),
                    'overdue' => now()->gt($due) && ($current === null || $current->status === SubmissionStatus::Returned),
                    'can_submit' => $current?->status !== SubmissionStatus::Accepted,
                ];
            })->values(),
            // Reports PSED sent back, from any month, newest first.
            'returned' => ReportSubmission::newestOf($rows)
                ->filter(fn (ReportSubmission $s) => $s->status === SubmissionStatus::Returned)
                ->sortByDesc('reviewed_at')->values()
                ->map(fn (ReportSubmission $s) => [
                    'id' => $s->id,
                    'type' => $s->type->value,
                    'label' => $s->type->label(),
                    'year' => $s->year,
                    'month' => $s->month,
                    'review_note' => $s->review_note,
                ]),
            'readiness' => $check['checked'] > 0
                ? ['checked' => $check['checked'], 'with_errors' => $check['with_errors'], 'with_warnings' => $check['with_warnings']]
                : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('submit-reports');

        $office = $request->user()->fieldOffice;

        $data = $request->validate([
            'type' => ['required', Rule::enum(ReportType::class)],
            'year' => ['required', 'integer', 'between:2020,'.now()->year],
            'month' => ['required', 'integer', 'between:1,12'],
            'file' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'file.required' => 'Choose the report file (.xlsx) to submit.',
            'file.mimes' => 'The report must be an Excel file (.xlsx).',
            'file.max' => 'The file is larger than 10 MB.',
        ]);

        $type = ReportType::from($data['type']);

        if (mktime(0, 0, 0, (int) $data['month'], 1, (int) $data['year']) > mktime(0, 0, 0, (int) now()->month, 1, (int) now()->year)) {
            throw ValidationException::withMessages(['month' => 'A report cannot be submitted for a month that has not started.']);
        }

        $latest = ReportSubmission::where('field_office_id', $office->id)->where('type', $type->value)
            ->where('year', $data['year'])->where('month', $data['month'])->latest('id')->first();

        if ($latest?->status === SubmissionStatus::Accepted) {
            throw ValidationException::withMessages(['file' => 'PSED has accepted this report. Ask PSED to return it if it needs to be replaced.']);
        }

        $file = $request->file('file');
        $submission = ReportSubmission::create([
            'field_office_id' => $office->id,
            'type' => $type,
            'year' => (int) $data['year'],
            'month' => (int) $data['month'],
            'status' => SubmissionStatus::Submitted,
            'file_path' => $file->store("report-submissions/{$office->id}/{$data['year']}-".sprintf('%02d', $data['month']), 'local'),
            'file_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'file_size' => $file->getSize(),
            'note' => $data['note'] ?? null,
            'submitted_by' => $request->user()->id,
            'submitted_at' => now(),
        ]);

        AuditLog::record('report.submit', $office, after: [
            'type' => $type->value, 'year' => $submission->year, 'month' => $submission->month,
            'resubmission' => $latest !== null, 'file' => $submission->file_name,
        ]);

        return back()->with('status', "{$type->label()} for ".date('F', mktime(0, 0, 0, $submission->month, 1))." {$submission->year} was submitted to PSED.");
    }

    /** The file as submitted: PSED, or the office that submitted it. */
    public function file(Request $request, ReportSubmission $submission): BinaryFileResponse
    {
        $user = $request->user();

        abort_unless($user->seesAllOffices() || $user->field_office_id === $submission->field_office_id, 403);
        abort_unless(Storage::disk('local')->exists($submission->file_path), 404);

        return response()->download(Storage::disk('local')->path($submission->file_path), $submission->file_name, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array{0: int, 1: int} the year and month shown: the one asked for, else last month; never a future one */
    public static function period(Request $request): array
    {
        $data = $request->validate([
            'year' => ['nullable', 'integer', 'between:2020,'.now()->year],
            'month' => ['nullable', 'integer', 'between:1,12'],
        ]);

        $default = now()->subMonthNoOverflow();
        $year = (int) ($data['year'] ?? $default->year);
        $month = (int) ($data['month'] ?? $default->month);

        if ($year === now()->year && $month > now()->month) {
            $month = now()->month;
        }

        return [$year, $month];
    }
}
