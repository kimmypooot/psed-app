<?php

namespace Tests\Unit;

use App\Enums\HeadType;
use App\Enums\Processor;
use App\Models\FieldOffice;
use App\Services\AppointmentRouter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Step 4 of the CSC RO VIII Appointments Processing Flow Chart.
 */
class AppointmentRouterTest extends TestCase
{
    /** @return array<string, array{HeadType, int, Processor}> */
    public static function cases(): array
    {
        $fo = Processor::FieldOffice;
        $psed = Processor::Psed;

        return [
            // Field Office headed by a Director II (SG 26): PSED from SG 26.
            'director: SG 25 stays' => [HeadType::DirectorII, 25, $fo],
            'director: SG 26 to PSED' => [HeadType::DirectorII, 26, $psed],
            'director: SG 30 to PSED' => [HeadType::DirectorII, 30, $psed],
            'director: SG 1 stays' => [HeadType::DirectorII, 1, $fo],
            // Headed by a Caretaker/OIC (SG 22): PSED from SG 22.
            'oic: SG 21 stays' => [HeadType::OicSg22, 21, $fo],
            'oic: SG 22 to PSED' => [HeadType::OicSg22, 22, $psed],
            'oic: SG 25 to PSED' => [HeadType::OicSg22, 25, $psed],
            // Default (no exception): PSED consolidates from SG 21.
            'default: SG 20 stays' => [HeadType::Standard, 20, $fo],
            'default: SG 21 to PSED' => [HeadType::Standard, 21, $psed],
            'default: SG 26 to PSED' => [HeadType::Standard, 26, $psed],
        ];
    }

    #[DataProvider('cases')]
    public function test_routes_by_the_head_of_the_field_office(HeadType $head, int $grade, Processor $expected): void
    {
        $office = new FieldOffice(['head_type' => $head]);

        $this->assertSame($expected, (new AppointmentRouter)->route($office, $grade));
    }

    public function test_an_unknown_office_is_treated_as_the_default_case(): void
    {
        $this->assertSame(Processor::Psed, (new AppointmentRouter)->route(null, 21));
        $this->assertSame(Processor::FieldOffice, (new AppointmentRouter)->route(null, 20));
    }
}
