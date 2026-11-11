<?php

namespace App\Models;

use App\Enums\HeadType;
use App\Services\AppointmentRouter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FieldOffice extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'imis_code', 'location', 'head_type', 'prepared_by_imis_id',
        'prepared_by_name', 'prepared_by_title', 'approved_by_name', 'approved_by_title',
    ];

    protected function casts(): array
    {
        return ['head_type' => HeadType::class];
    }

    protected static function booted(): void
    {
        // If the head of the office changes (e.g. a Director II is appointed),
        // every appointment already encoded must be re-routed.
        static::updated(function (self $office) {
            if ($office->wasChanged('head_type')) {
                app(AppointmentRouter::class)->rerouteOffice($office);
            }
        });
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
