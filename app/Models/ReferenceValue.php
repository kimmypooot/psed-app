<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Dropdown source lists (sectors, agencies, positions, eligibility types, ...). */
class ReferenceValue extends Model
{
    protected $fillable = ['type', 'value', 'field_office_id'];
}
