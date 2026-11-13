<?php

namespace App\Services;

use App\Enums\ReportType;
use App\Models\ReportDeadline;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * When each monthly report is due. A month's own date, if PSED set one, wins; otherwise the report's usual
 * day of the following month; otherwise the configured default.
 */
class ReportDeadlines
{
    /** @var Collection<int, ReportDeadline>|null */
    private ?Collection $rows = null;

    public function dueDate(ReportType $type, int $year, int $month): Carbon
    {
        $own = $this->rows()->first(fn (ReportDeadline $d) => $d->type === $type && $d->year === $year && $d->month === $month && $d->due_date !== null);

        if ($own) {
            return Carbon::parse($own->due_date)->endOfDay();
        }

        return Carbon::create($year, $month, 1)->addMonthNoOverflow()->day($this->day($type))->endOfDay();
    }

    /**
     * How timely a submission was: 5.0 if it came in before the deadline day, 4.0 on the deadline day itself,
     * and null (no rating) if it was late.
     */
    public function rating(Carbon $due, Carbon $submittedAt): ?float
    {
        $days = (int) $submittedAt->copy()->startOfDay()->diffInDays($due->copy()->startOfDay(), false);

        return match (true) {
            $days >= 1 => 5.0,
            $days === 0 => 4.0,
            default => null,
        };
    }

    /** The usual day of the following month a report is due. */
    public function day(ReportType $type): int
    {
        $usual = $this->rows()->first(fn (ReportDeadline $d) => $d->type === $type && $d->year === null && $d->due_day !== null);

        return $usual?->due_day ?? (int) config('psed.reports.due_day', 5);
    }

    /** @return array<string, int> the usual day for each report type */
    public function days(): array
    {
        return collect(ReportType::cases())->mapWithKeys(fn (ReportType $t) => [$t->value => $this->day($t)])->all();
    }

    /** @return Collection<int, ReportDeadline> the dates set for single months, newest month first */
    public function overrides(): Collection
    {
        return $this->rows()->filter(fn (ReportDeadline $d) => $d->year !== null)
            ->sortBy([['year', 'desc'], ['month', 'desc'], ['type', 'asc']])->values();
    }

    public function forget(): void
    {
        $this->rows = null;
    }

    /** @return Collection<int, ReportDeadline> */
    private function rows(): Collection
    {
        return $this->rows ??= ReportDeadline::all();
    }
}
