<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\FieldOffice;
use App\Models\OpeningBalance;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The balance a Field Office carries into a year: its appointments still unacted on 31 December of the
 * year before (the MRAP's "Beginning Balance" and section G).
 *
 * PSED, the consolidator of the Field Offices' reports, sets it per office and year. This matters most in
 * the first year of the system, whose records start with the year's first appointment and so cannot say what
 * was outstanding before it. Where PSED has set nothing, it is worked out from the appointments the system
 * does hold, so later years need no entry at all.
 */
class OpeningBalances
{
    public const SOURCE_PSED = 'psed';

    public const SOURCE_SYSTEM = 'system';

    /**
     * The balances PSED has set for a year.
     *
     * @return Collection<int, OpeningBalance> keyed by Field Office id
     */
    public function setFor(int $year): Collection
    {
        return OpeningBalance::with('updater:id,name')->where('year', $year)->get()->keyBy('field_office_id');
    }

    /**
     * Just the numbers PSED set, for the reports.
     *
     * @return array<int, int> Field Office id => unacted
     */
    public function overrides(int $year): array
    {
        return OpeningBalance::where('year', $year)->pluck('unacted', 'field_office_id')->map(fn ($n) => (int) $n)->all();
    }

    /**
     * What the system's own appointments say: received before the year and not acted by its 31 December
     * (acting after it still leaves them unacted at that date).
     *
     * @return array<int, int> Field Office id => unacted (offices with none are absent)
     */
    public function fromSystem(int $year): array
    {
        return Appointment::query()
            ->where('date_received', '<', $year.'-01-01')
            ->unactedAsOf(now()->setDate($year - 1, 12, 31))
            ->selectRaw('field_office_id, COUNT(*) AS total')
            ->groupBy('field_office_id')
            ->toBase()->pluck('total', 'field_office_id')->map(fn ($n) => (int) $n)->all();
    }

    /**
     * The balance in use for one office and year, and where it came from.
     *
     * @return array{value: int, source: string}
     */
    public function for(FieldOffice $office, int $year): array
    {
        $set = $this->overrides($year)[$office->id] ?? null;

        return $set !== null
            ? ['value' => $set, 'source' => self::SOURCE_PSED]
            : ['value' => $this->fromSystem($year)[$office->id] ?? 0, 'source' => self::SOURCE_SYSTEM];
    }

    /**
     * Sets an office's balance for a year; null takes PSED's figure away, so the system's own count is used again.
     */
    public function set(FieldOffice $office, int $year, ?int $unacted, ?string $note, User $by): void
    {
        $existing = OpeningBalance::where('field_office_id', $office->id)->where('year', $year)->first();

        if ($unacted === null) {
            if ($existing) {
                AuditLog::record('opening_balance.clear', $office, before: ['year' => $year, 'unacted' => $existing->unacted, 'summary' => "Cleared the {$year} opening balance of {$office->name}."]);
                $existing->delete();
            }

            return;
        }

        $note = filled($note) ? $note : null;

        if ($existing && $existing->unacted === $unacted && $existing->note === $note) {
            return;
        }

        OpeningBalance::updateOrCreate(
            ['field_office_id' => $office->id, 'year' => $year],
            ['unacted' => $unacted, 'note' => $note, 'updated_by' => $by->id],
        );

        AuditLog::record(
            'opening_balance.set',
            $office,
            before: $existing ? ['year' => $year, 'unacted' => $existing->unacted] : null,
            after: ['year' => $year, 'unacted' => $unacted, 'summary' => "Set the {$year} opening balance of {$office->name} to {$unacted}."],
        );
    }
}
