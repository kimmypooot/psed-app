<?php

namespace App\Services\Reports;

use App\Models\Appointment;
use App\Models\FieldOffice;
use App\Services\DirectoryUnavailable;
use App\Services\ImisDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * What a report is about: one month of one year, for one Field Office, or
 * for all of them (`$office` null: the consolidated report PSED prepares).
 */
final class ReportContext
{
    /** Printed in place of an office name on a consolidated report. */
    public const REGION_LABEL = 'CSC Regional Office VIII (all Field Offices)';

    /** Stands in for the office code in file names and cells. */
    public const REGION_CODE = 'RO8';

    public readonly CarbonImmutable $start;

    public readonly CarbonImmutable $end;

    public readonly CarbonImmutable $yearStart;

    public function __construct(
        public readonly ?FieldOffice $office,
        public readonly int $year,
        public readonly int $month,
    ) {
        $this->start = CarbonImmutable::create($year, $month, 1)->startOfDay();
        $this->end = $this->start->endOfMonth()->startOfDay();
        $this->yearStart = CarbonImmutable::create($year, 1, 1)->startOfDay();
    }

    /** @var array{prepared: ?array{name: string, title: ?string}, approved: ?array{name: string, title: ?string}}|null */
    private ?array $signatories = null;

    public function isConsolidated(): bool
    {
        return $this->office === null;
    }

    /**
     * Who signs the report. "Prepared by" is the staff member of the office chosen for it, and
     * "Approved / noted by" is the office's head today in IMIS: the Director II, or while designated
     * the Officer-in-Charge or Caretaker. Both are read from IMIS when the report is made, so they are
     * current; if IMIS has nothing or cannot be reached, what an administrator last saved is used.
     * A consolidated report has neither: it is signed by hand.
     *
     * @return array{prepared: ?array{name: string, title: ?string}, approved: ?array{name: string, title: ?string}}
     */
    public function signatories(): array
    {
        if ($this->signatories !== null) {
            return $this->signatories;
        }

        $office = $this->office;
        if ($office === null) {
            return $this->signatories = ['prepared' => null, 'approved' => null];
        }

        $prepared = $approved = null;

        try {
            $directory = app(ImisDirectory::class);
            $person = $office->prepared_by_imis_id ? $directory->find($office->prepared_by_imis_id) : null;
            $head = $directory->headOf($office->imis_code);

            $prepared = $person ? ['name' => ImisDirectory::signatureName($person), 'title' => $person['position']] : null;
            $approved = $head ? ['name' => ImisDirectory::signatureName($head['employee']), 'title' => $head['title']] : null;
        } catch (DirectoryUnavailable) {
            // fall back to what was saved
        }

        return $this->signatories = [
            'prepared' => $prepared ?? ($office->prepared_by_name ? ['name' => $office->prepared_by_name, 'title' => $office->prepared_by_title] : null),
            'approved' => $approved ?? ($office->approved_by_name ? ['name' => $office->approved_by_name, 'title' => $office->approved_by_title] : null),
        ];
    }

    /** The same report for another month. */
    public function forMonth(int $month): self
    {
        return new self($this->office, $this->year, $month);
    }

    /** The office's appointments, or everyone's when consolidated. */
    public function appointments(): Builder
    {
        return Appointment::query()
            ->when($this->office, fn (Builder $q, FieldOffice $o) => $q->where('field_office_id', $o->id));
    }

    public function officeName(): string
    {
        return $this->office?->name ?? self::REGION_LABEL;
    }

    /** "October" */
    public function monthName(): string
    {
        return $this->start->format('F');
    }

    /** First day of the following month, which the reports are dated. */
    public function nextMonthStart(): CarbonImmutable
    {
        return $this->start->addMonth();
    }
}
