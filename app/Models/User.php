<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
