<?php

namespace App\Models;

use App\Enums\ReferenceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferenceDocument extends Model
{
    protected $fillable = [
        'title', 'type', 'reference_no', 'issued_on', 'summary', 'url',
        'file_path', 'file_name', 'file_mime', 'file_size', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => ReferenceType::class,
            'issued_on' => 'date',
            'file_size' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function hasFile(): bool
    {
        return $this->file_path !== null;
    }

    public function isPdf(): bool
    {
        return $this->file_mime === 'application/pdf';
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
}
