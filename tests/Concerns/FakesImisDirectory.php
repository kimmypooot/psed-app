<?php

namespace Tests\Concerns;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Points CARES at a pretend IMIS people directory (GET /api/v1/cares/employees)
 * that answers like the real one. Tests otherwise run with no IMIS at all.
 */
trait FakesImisDirectory
{
    public const IMIS_URL = 'http://imis.test';

    /** @return array<string, mixed> the people IMIS would list */
    protected function imisPeople(): array
    {
        $person = fn (int $id, string $name, string $position, ?int $grade, string $code, string $office, string $type = 'field_office') => [
            'id' => $id, 'name' => $name, 'first_name' => null, 'middle_name' => null, 'last_name' => null, 'name_extension' => null,
            'position' => $position, 'salary_grade' => $grade, 'office_code' => $code, 'office_name' => $office, 'office_type' => $type,
        ];

        $east = 'CSC Field Office - Eastern Samar';
        $samar = 'CSC Field Office - Samar';
        $ord = 'Office of the Regional Director';

        return [
            'data' => [
                $person(1, 'Ryan Jose M. Estoque', 'Senior Human Resource Specialist', 19, 'ESFO', $east),
                $person(2, 'Rey Albert B. Uy', 'Director II', 26, 'SFO', $samar),
                $person(3, 'Maria L. Santos', 'Supervising Human Resource Specialist', 22, 'ESFO', $east),
                $person(4, 'Pedro P. Dizon', 'Chief Human Resource Specialist', 24, 'PSED', 'Policies and Systems Evaluation Division', 'regional_support_unit'),
                $person(5, 'Juan Dela Cruz', 'Administrative Aide VI', 6, 'ESFO', $east),
                $person(6, 'Juan C. Dela Cruz', 'Administrative Aide IV', 4, 'SFO', $samar),
                $person(7, 'Ana P. Reyes', 'Human Resource Specialist I', 11, 'SFO', $samar),
                // The Office of the Regional Director: its Director IV and Director III act on what PSED processes.
                $person(9, 'Benito C. Lim', 'Director III', 27, 'ORD', $ord, 'regional_support_unit'),
                $person(8, 'Alicia R. Mendoza', 'Director IV', 28, 'ORD', $ord, 'regional_support_unit'),
                $person(10, 'Carla D. Ramos', 'Administrative Aide VI', 6, 'ORD', $ord, 'regional_support_unit'),
            ],
            'heads' => [
                // The Director II of Samar also heads Eastern Samar at the same time. IMIS records that second
                // office as an 'oic' designation (and labels it so); CARES calls it Concurrent and must not
                // depend on IMIS's label.
                ['office_code' => 'ESFO', 'office_name' => $east, 'office_type' => 'field_office', 'user_id' => 2, 'capacity' => 'oic', 'capacity_label' => 'Officer-in-Charge'],
                ['office_code' => 'SFO', 'office_name' => $samar, 'office_type' => 'field_office', 'user_id' => 2, 'capacity' => null, 'capacity_label' => null],
            ],
        ];
    }

    /** CARES knows where IMIS is, and IMIS answers with the given people (default: the usual ones). */
    protected function fakeImisDirectory(?array $people = null): void
    {
        config(['services.imis.api_url' => self::IMIS_URL, 'services.imis.api_token' => 'directory-token']);
        Cache::flush();
        Http::swap(new HttpFactory); // fakes stack, and the first one wins: start clean

        Http::fake([self::IMIS_URL.'/api/v1/cares/employees' => Http::response($people ?? $this->imisPeople())]);
    }

    /** CARES knows where IMIS is, but IMIS does not answer. */
    protected function imisIsDown(): void
    {
        config(['services.imis.api_url' => self::IMIS_URL, 'services.imis.api_token' => 'directory-token']);
        Cache::flush();
        Http::swap(new HttpFactory);

        Http::fake([self::IMIS_URL.'/*' => Http::response('down', 503)]);
    }
}
