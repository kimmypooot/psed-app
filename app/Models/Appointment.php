<?php

namespace App\Models;

use App\Enums\Processor;
use App\Services\AppointmentRouter;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class Appointment extends Model
{
    use HasFactory;

    protected $guarded = ['id', 'processed_by'];

    protected function casts(): array
    {
        return [
            'date_received' => 'date',
            'date_issued' => 'date',
            'date_effective' => 'date',
            'publication_start' => 'date',
            'publication_end' => 'date',
            'eligibility_issued_date' => 'date',
            'eligibility_validity' => 'date',
            'action_date' => 'date',
            'release_date' => 'date',
            'salary_grade' => 'integer',
            'is_first_time_entrant' => 'boolean',
            'is_pwd' => 'boolean',
            'is_indigenous' => 'boolean',
            'is_solo_parent' => 'boolean',
            'is_k12_graduate' => 'boolean',
            'processed_by' => Processor::class,
        ];
    }

    /** Bumped on every change so cached dashboard figures are never stale after an edit. */
    public static function statsVersion(): int
    {
        return (int) Cache::get('appointments.stats_version', 1);
    }

    public static function bumpStatsVersion(): void
    {
        Cache::add('appointments.stats_version', 1);
        Cache::increment('appointments.stats_version');
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::bumpStatsVersion());
        static::deleted(fn () => self::bumpStatsVersion());

        // Step 4 of the flow chart: decide who processes it, every time it is saved.
        static::saving(function (self $appointment) {
            // The SK position belongs to the SKOE alone; any other eligibility leaves none behind.
            if ($appointment->eligibility_type !== config('psed.skoe.eligibility')) {
                $appointment->skoe_position = null;
            }

            $appointment->processed_by = app(AppointmentRouter::class)
                ->route($appointment->fieldOffice, (int) $appointment->salary_grade);
        });
    }

    public function fieldOffice(): BelongsTo
    {
        return $this->belongsTo(FieldOffice::class);
    }

    // ---------------------------------------------------------------- accessors

    /** "Last, First Middle" (extension appended to the last name when present). */
    public function getFullNameAttribute(): string
    {
        $last = trim($this->last_name.' '.$this->extensionLabel());

        return trim($last.', '.$this->first_name.' '.($this->middle_name ?? ''));
    }

    /**
     * Who processes it, by name: "PSED", or the specific Field Office (the
     * office that encoded it), not just the kind of office.
     */
    public function getProcessedByNameAttribute(): string
    {
        return $this->processed_by === Processor::Psed
            ? Processor::Psed->label()
            : ($this->fieldOffice?->name ?? Processor::FieldOffice->label());
    }

    public function extensionLabel(): string
    {
        $ext = trim((string) $this->name_extension);

        return strtoupper($ext) === 'N/A' ? '' : $ext;
    }

    /** True when the MOA reports this one on the "CasualCon" sheet. */
    public function isCasualContractual(): bool
    {
        return in_array($this->employment_status, config('psed.casual_contractual_statuses'), true);
    }

    public function isActed(): bool
    {
        return $this->action_date !== null
            && in_array($this->csc_action, config('psed.final_actions'), true);
    }

    // ------------------------------------------------------------------- scopes

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        // MySQL / MariaDB: every word must start a word in the name, agency or position, via the FULLTEXT
        // index (see the appointments_search_ft migration), so searching stays fast at any size.
        // Words under 3 characters are below the index's minimum, so they use the LIKE scan below.
        $words = preg_split('/[^\p{L}\p{N}]+/u', $term, -1, PREG_SPLIT_NO_EMPTY);

        if (in_array($query->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)
            && $words !== [] && min(array_map('mb_strlen', $words)) >= 3) {
            return $query->whereRaw(
                'MATCH(last_name, first_name, middle_name, agency, position) AGAINST (? IN BOOLEAN MODE)',
                [implode(' ', array_map(fn (string $w) => '+'.$w.'*', $words))],
            );
        }

        return $query->where(function (Builder $q) use ($term) {
            // % and _ typed by a user are characters to find, not wildcards.
            $like = '%'.addcslashes($term, '\\%_').'%';
            $q->where('last_name', 'like', $like)
                ->orWhere('first_name', 'like', $like)
                ->orWhere('middle_name', 'like', $like)
                ->orWhere('agency', 'like', $like)
                ->orWhere('position', 'like', $like);
        });
    }

    // The MOA has two sheets, and an appointment belongs to one of them by its employment status: Casual and
    // Contractual go to "CasualCon", everything else to "Plantilla" (see config psed.casual_contractual_statuses).
    // The lists, the MOA report and the import all use this one rule, so they never disagree.

    public const TYPE_PLANTILLA = 'plantilla';

    public const TYPE_CASUAL = 'casual-contractual';

    public function scopePlantilla(Builder $query): Builder
    {
        return $query->whereNotIn('employment_status', config('psed.casual_contractual_statuses'));
    }

    public function scopeCasualContractual(Builder $query): Builder
    {
        return $query->whereIn('employment_status', config('psed.casual_contractual_statuses'));
    }

    /** One MOA sheet's appointments by its URL name (null: both). */
    public function scopeOfType(Builder $query, ?string $type): Builder
    {
        return match ($type) {
            self::TYPE_PLANTILLA => $query->plantilla(),
            self::TYPE_CASUAL => $query->casualContractual(),
            default => $query,
        };
    }

    public function scopeReceivedBetween(Builder $query, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query->whereBetween('date_received', [$from->toDateString(), $to->toDateString()]);
    }

    /** Acted upon (approved, disapproved, invalidated or validated) with an action date. */
    public function scopeActed(Builder $query): Builder
    {
        return $query->whereNotNull('action_date')
            ->whereIn('csc_action', config('psed.final_actions'));
    }

    public function scopeActedBetween(Builder $query, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query->acted()
            ->whereBetween('action_date', [$from->toDateString(), $to->toDateString()]);
    }

    /** Still waiting for action as of the end of $date. */
    public function scopeUnactedAsOf(Builder $query, CarbonInterface $date): Builder
    {
        return $query->where(function (Builder $q) use ($date) {
            $q->whereNull('action_date')
                ->orWhereNull('csc_action')
                ->orWhere('csc_action', 'Pending')
                ->orWhere('action_date', '>', $date->toDateString());
        });
    }

    /** Backlog: nothing final has been done yet. */
    public function scopeUnacted(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('action_date')
                ->orWhereNull('csc_action')
                ->orWhere('csc_action', 'Pending');
        });
    }

    public function scopeFirstTimeApprovedBetween(Builder $query, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query->where('is_first_time_entrant', true)
            ->whereIn('csc_action', config('psed.approved_actions'))
            ->whereBetween('action_date', [$from->toDateString(), $to->toDateString()]);
    }
}
