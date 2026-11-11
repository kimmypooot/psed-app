<?php

namespace App\Enums;

/** Where a Field Office's submitted report stands with PSED. */
enum SubmissionStatus: string
{
    case Submitted = 'submitted';
    case Accepted = 'accepted';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::Accepted => 'Accepted',
            self::Returned => 'Returned for correction',
        };
    }
}
