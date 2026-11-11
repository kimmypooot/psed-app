<?php

namespace App\Models;

use App\Enums\Processor;
use App\Services\AppointmentRouter;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    protected static function booted(): void
    {
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

        return $query->where(function (Builder $q) use ($term) {
            $like = '%'.$term.'%';
            $q->where('last_name', 'like', $like)
                ->orWhere('first_name', 'like', $like)
                ->orWhere('middle_name', 'like', $like)
                ->orWhere('agency', 'like', $like)
                ->orWhere('position', 'like', $like);
        });
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
