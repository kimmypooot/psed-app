<?php

namespace App\Console\Commands;

use App\Enums\ReportType;
use App\Enums\SubmissionStatus;
use App\Models\FieldOffice;
use App\Models\ReportSubmission;
use App\Models\User;
use App\Services\ReportDeadlines;
use Illuminate\Console\Command;

/**
 * Demo data for Reports > Submissions: every office gets a spread of early, on-the-day, late and missing
 * submissions for last month, so the Rating columns can be seen. Demo rows are tagged and removable.
 */
class SeedReportDemo extends Command
{
    protected $signature = 'reports:seed-demo {--fresh : Remove earlier demo rows first}';

    protected $description = 'Seed demo report submissions (early, on the deadline, late, missing) for last month';

    private const TAG = '[demo]';

    public function handle(ReportDeadlines $deadlines): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing to seed demo data in production.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            ReportSubmission::where('note', self::TAG)->delete();
        }

        $period = now()->subMonthNoOverflow();
        $user = User::first();

        // Days from the deadline: negative = early, 0 = on the day, positive = late, null = not submitted.
        $pattern = [-3, -1, 0, 0, 2, null, -1, 1];
        $count = 0;

        foreach (FieldOffice::orderBy('id')->get() as $i => $office) {
            foreach (ReportType::cases() as $j => $type) {
                $offset = $pattern[($i * 3 + $j) % count($pattern)];

                if ($offset === null) {
                    continue;
                }

                $due = $deadlines->dueDate($type, $period->year, $period->month);
                $at = $due->copy()->addDays($offset)->setTime(9 + ($i + $j) % 8, 30);
                $status = match (($i + $j) % 3) {
                    0 => SubmissionStatus::Accepted,
                    1 => SubmissionStatus::Submitted,
                    default => SubmissionStatus::Returned,
                };

                ReportSubmission::create([
                    'field_office_id' => $office->id,
                    'type' => $type,
                    'year' => $period->year,
                    'month' => $period->month,
                    'status' => $status,
                    'file_path' => 'report-submissions/demo/'.$type->value.'.xlsx',
                    'file_name' => strtoupper($type->value).' - '.$office->name.'.xlsx',
                    'file_size' => 48000,
                    'note' => self::TAG,
                    'submitted_by' => $user?->id,
                    'submitted_at' => $at,
                    'reviewed_by' => $status === SubmissionStatus::Submitted ? null : $user?->id,
                    'reviewed_at' => $status === SubmissionStatus::Submitted ? null : $at->copy()->addDay(),
                    'review_note' => $status === SubmissionStatus::Returned ? 'Demo: please correct the totals.' : null,
                ]);
                $count++;
            }
        }

        $this->info("Seeded {$count} demo submissions for {$period->format('F Y')}. They are the rows with the note [demo].");

        return self::SUCCESS;
    }
}
