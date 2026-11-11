<?php

namespace App\Models;

use App\Enums\ReportType;
use App\Enums\SubmissionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class ReportSubmission extends Model
{
    protected $fillable = [
        'field_office_id', 'type', 'year', 'month', 'status', 'file_path', 'file_name', 'file_size', 'note',
        'submitted_by', 'submitted_at', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected function casts(): array
    {
        return [
            'type' => ReportType::class,
            'status' => SubmissionStatus::class,
            'year' => 'integer',
            'month' => 'integer',
            'file_size' => 'integer',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function fieldOffice(): BelongsTo
    {
        return $this->belongsTo(FieldOffice::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * The submissions that count: for each office and report type, the newest upload.
     *
     * @param  Collection<int, self>  $submissions
     * @return Collection<string, self> keyed "officeId:type"
     */
    public static function newestOf(Collection $submissions): Collection
    {
        return $submissions->sortBy('id')->keyBy(fn (self $s) => "{$s->field_office_id}:{$s->type->value}");
    }

    /** "1.4 MB" */
    public function fileSizeLabel(): ?string
    {
        if ($this->file_size === null) {
            return null;
        }

        return $this->file_size >= 1048576
            ? number_format($this->file_size / 1048576, 1).' MB'
            : max(1, (int) round($this->file_size / 1024)).' KB';
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'file_name' => $this->file_name,
            'file_size' => $this->fileSizeLabel(),
            'note' => $this->note,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'submitted_by' => $this->submitter?->name,
            'review_note' => $this->review_note,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'reviewed_by' => $this->reviewer?->name,
        ];
    }
}
