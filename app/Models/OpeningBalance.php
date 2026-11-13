<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The appointments a Field Office carries into `year`: unacted on 31 December of the year before, as set by PSED. */
class OpeningBalance extends Model
{
    protected $fillable = ['field_office_id', 'year', 'unacted', 'note', 'updated_by'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'unacted' => 'integer'];
    }

    public function fieldOffice(): BelongsTo
    {
        return $this->belongsTo(FieldOffice::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
