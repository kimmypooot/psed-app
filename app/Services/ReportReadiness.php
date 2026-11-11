<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\FieldOffice;
use App\Services\Reports\ReportContext;
use Illuminate\Support\Collection;

/**
 * Looks over the appointments that go into a month's reports and says what
 * would print as "N/A" or throw a figure off, so the office can fix it first.
 *
 * "Error" means the figure itself is wrong or the appointment is left out of a
 * count; "warning" means a cell of the report will read "N/A".
 */
class ReportReadiness
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    private const ALL = ['IPMR', 'MRAP', 'MOA', 'Absorption'];

    /**
     * @return array{checked: int, with_errors: int, with_warnings: int, shown: int, issues: list<array<string, mixed>>}
     */
    public function check(?FieldOffice $office, int $year, int $month, int $limit = 200): array
    {
        $context = new ReportContext($office, $year, $month);

        // The same appointments the month's MOA lists: received in it, or acted upon in it.
        $appointments = $context->appointments()
            ->where(function ($q) use ($context) {
                $q->whereBetween('date_received', [$context->start->toDateString(), $context->end->toDateString()])
                    ->orWhereBetween('action_date', [$context->start->toDateString(), $context->end->toDateString()]);
            })
            ->with('fieldOffice:id,name')
            ->orderBy('date_received')->orderBy('last_name')
            ->get();

        $issues = $appointments
            ->map(fn (Appointment $a) => ['appointment' => $a, 'problems' => $this->problems($a)])
            ->filter(fn (array $row) => $row['problems'] !== [])
            // Appointments with an error first.
            ->sortBy(fn (array $row) => collect($row['problems'])->contains('severity', self::ERROR) ? 0 : 1)
            ->values();

        return [
            'checked' => $appointments->count(),
            'with_errors' => $issues->filter(fn ($r) => collect($r['problems'])->contains('severity', self::ERROR))->count(),
            'with_warnings' => $issues->filter(fn ($r) => ! collect($r['problems'])->contains('severity', self::ERROR))->count(),
            'shown' => min($limit, $issues->count()),
            'issues' => $issues->take($limit)->map(fn (array $row) => [
                'id' => $row['appointment']->id,
                'name' => $row['appointment']->full_name,
                'office' => $row['appointment']->fieldOffice?->name,
                'problems' => $row['problems'],
            ])->all(),
        ];
    }

    /** @return list<array{severity: string, message: string, affects: list<string>}> */
    private function problems(Appointment $a): array
    {
        $p = new class
        {
            /** @var list<array{severity: string, message: string, affects: list<string>}> */
            public array $list = [];

            public function add(string $severity, string $message, array $affects): void
            {
                $this->list[] = ['severity' => $severity, 'message' => $message, 'affects' => $affects];
            }
        };

        $final = in_array($a->csc_action, config('psed.final_actions'), true);
        $rejected = in_array($a->csc_action, config('psed.rejected_actions'), true);
        $hasEligibility = filled($a->eligibility_type) && $a->eligibility_type !== 'N/A';
        $blank = fn ($v) => ! filled($v) || $v === 'N/A';

        // --- Errors: the figures are wrong, or the appointment is left out of a count --------------
        if ($final && $a->action_date === null) {
            $p->add(self::ERROR, 'It was acted upon but has no date of action, so no report counts it as acted.', self::ALL);
        }
        if ($a->action_date !== null && $a->action_date->lt($a->date_received)) {
            $p->add(self::ERROR, 'The date of action is before the date received.', self::ALL);
        }
        if ($rejected && $blank($a->disapproval_reason)) {
            $p->add(self::ERROR, 'It was disapproved or invalidated but no reason is given.', ['MOA']);
        }
        if ($a->is_first_time_entrant && ! $hasEligibility) {
            $p->add(self::ERROR, 'It is marked as a first-time entrant but has no eligibility, so the Absorption Report will show "N/A" as the eligibility used.', ['Absorption', 'IPMR']);
        }
        if ($a->csc_action === 'Pending' && $a->action_date !== null) {
            $p->add(self::ERROR, 'It is marked Pending but has a date of action, so it is counted as unacted.', ['MRAP', 'IPMR']);
        }

        // --- Warnings: a cell of the report will read "N/A" ------------------------------------------
        if ($final && $blank($a->action_officer)) {
            $p->add(self::WARNING, 'No action officer.', ['MOA']);
        }
        if ($final && $a->release_date === null) {
            $p->add(self::WARNING, 'No date of release yet.', ['MOA']);
        }
        if ($a->csc_action === 'Pending' && $blank($a->pending_reason)) {
            $p->add(self::WARNING, 'Pending with no reason given.', ['MOA']);
        }
        if ($hasEligibility && $a->eligibility_issued_date === null && $blank($a->eligibility_exam_date)) {
            $p->add(self::WARNING, 'The eligibility has neither a date of issuance nor a date of exam.', ['MOA', 'Absorption']);
        }
        if ($a->date_issued === null || $a->date_effective === null) {
            $p->add(self::WARNING, 'No date of issuance or of effectivity.', ['MOA']);
        }
        if ($blank($a->received_by) || $a->time_received === null) {
            $p->add(self::WARNING, 'No time received or no one recorded as having received it.', ['MOA']);
        }
        if ($blank($a->sector)) {
            $p->add(self::WARNING, 'No sector.', ['MOA']);
        }
        if ($blank($a->level)) {
            $p->add(self::WARNING, 'No level (1st / 2nd).', ['MOA', 'Absorption']);
        }

        return $p->list;
    }
}
