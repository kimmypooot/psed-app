<?php

namespace App\Enums;

/** What kind of guide a document on the Qualification Standards page is. */
enum ReferenceType: string
{
    case QualificationStandard = 'qualification_standard';
    case Issuance = 'issuance';
    case Memorandum = 'memorandum';
    case Policy = 'policy';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::QualificationStandard => 'Qualification Standard',
            self::Issuance => 'Issuance',
            self::Memorandum => 'Memorandum',
            self::Policy => 'Policy',
            self::Other => 'Other guide',
        };
    }

    /** Badge colour (see Badge.vue tones). The label always says it too, so colour is never the only signal. */
    public function tone(): string
    {
        return match ($this) {
            self::QualificationStandard => 'success',
            self::Issuance => 'info',
            self::Memorandum => 'warning',
            self::Policy, self::Other => 'neutral',
        };
    }
}
