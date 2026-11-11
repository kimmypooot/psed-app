<?php

namespace App\Enums;

/** Which office processes an appointment. */
enum Processor: string
{
    case FieldOffice = 'field_office';
    case Psed = 'psed';

    public function label(): string
    {
        return match ($this) {
            self::FieldOffice => 'Field Office',
            self::Psed => 'PSED',
        };
    }
}
