<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A staff member, as IMIS last described them. A stub: created and rewritten
 * from IMIS at each sign-in (ImisSsoController) and never given a password.
 */
#[Fillable(['imis_user_id', 'username', 'name', 'email', 'position', 'role', 'photo_url', 'field_office_id', 'last_login_at'])]
#[Hidden(['remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    /**
     * The person's own office, kept here while `field_office_id` holds the office they
     * are working as this request (ApplyActiveOffice). Never stored.
     */
    public ?int $homeOfficeId = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'imis_user_id' => 'integer',
            'role' => UserRole::class,
            'last_login_at' => 'datetime',
        ];
    }

    public function fieldOffice(): BelongsTo
    {
        return $this->belongsTo(FieldOffice::class);
    }

    /**
     * The other Field Offices this person works as (see the field_office_user migration).
     *
     * @return BelongsToMany<FieldOffice, $this>
     */
    public function extraOffices(): BelongsToMany
    {
        return $this->belongsToMany(FieldOffice::class, 'field_office_user');
    }

    /**
     * Every Field Office a Field Office employee may work as: their own plus the extra
     * ones, own office first. Always empty for PSED, who see every office anyway.
     *
     * @return list<int>
     */
    public function workOfficeIds(): array
    {
        if ($this->role !== UserRole::FieldOffice) {
            return [];
        }

        return collect([$this->homeOfficeId ?? $this->field_office_id])
            ->merge($this->extraOffices()->pluck('field_offices.id'))
            ->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /** PSED staff and administrators work across every Field Office. */
    public function seesAllOffices(): bool
    {
        return $this->role->seesAllOffices();
    }
}
