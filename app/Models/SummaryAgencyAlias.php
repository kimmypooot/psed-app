<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A CARES agency paired by hand with a row of the Summary Database workbook (or marked as having none). */
class SummaryAgencyAlias extends Model
{
    protected $fillable = ['field_office_id', 'agency', 'template_agency', 'updated_by'];

    public function fieldOffice(): BelongsTo
    {
        return $this->belongsTo(FieldOffice::class);
    }
}
