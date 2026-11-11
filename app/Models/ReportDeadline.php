<?php

namespace App\Models;

use App\Enums\ReportType;
use Illuminate\Database\Eloquent\Model;

class ReportDeadline extends Model
{
    protected $fillable = ['type', 'year', 'month', 'due_day', 'due_date', 'updated_by'];

    protected function casts(): array
    {
        return [
            'type' => ReportType::class,
            'year' => 'integer',
            'month' => 'integer',
            'due_day' => 'integer',
            'due_date' => 'date',
        ];
    }
}
