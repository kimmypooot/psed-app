<?php

namespace App\Enums;

/**
 * A staff member's role here. It is not granted per person: it follows the
 * IMIS unit the person belongs to (see ImisSsoController), so PSED employees
 * are the administrators and Field Office employees work as their own office.
 */
enum UserRole: string
{
    /** An employee of the Policies and Systems Evaluation Division: everything, across every Field Office. */
    case Admin = 'admin';

    /** An employee of a Field or Satellite Office: encodes and processes appointments of that office. */
    case FieldOffice = 'field-office';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'PSED Administrator',
            self::FieldOffice => 'Field Office',
        };
    }

    /** Whether this role works across all Field Offices. */
    public function seesAllOffices(): bool
    {
        return $this === self::Admin;
    }
}
