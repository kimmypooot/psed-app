<?php

namespace App\Enums;

/**
 * Who heads a CSC Field Office. This is what the routing rules of the
 * Appointments Processing Flow Chart (step 4) hang on.
 */
enum HeadType: string
{
    /** Headed by a Director II (SG 26). */
    case DirectorII = 'director_ii';

    /** No Director II; a Caretaker / Officer-in-Charge with SG 22 heads the office. */
    case OicSg22 = 'oic_sg22';

    /** No exception: the standard (default) case. */
    case Standard = 'none';

    /** Lowest appointment SG that the Field Office must pass on to PSED. */
    public function psedThreshold(): int
    {
        return match ($this) {
            self::DirectorII => 26,
            self::OicSg22 => 22,
            self::Standard => 21,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::DirectorII => 'Headed by Director II (SG 26)',
            self::OicSg22 => 'Headed by Caretaker / OIC (SG 22)',
            self::Standard => 'Default (no exception)',
        };
    }
}
