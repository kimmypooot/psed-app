<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The CSC RO VIII people directory, from IMIS (GET /api/v1/cares/employees,
 * server to server with a bearer token): who can be named as an action
 * officer, who the staff of each Field Office are, and who heads each office
 * today (the Director II, or while designated the OIC or Caretaker), who
 * approves its reports.
 *
 * The answer is cached for a few minutes, and a stale copy is kept for a day,
 * so a short IMIS outage does not stop anyone from encoding or reporting.
 * When there is no copy at all, DirectoryUnavailable says why, and callers
 * keep what is already saved.
 */
class ImisDirectory
{
    private const FRESH = 'imis.directory';

    private const STALE = 'imis.directory.stale';

    private const FRESH_SECONDS = 300;

    /**
     * How CARES names the capacity in which someone heads an office by designation. IMIS records
     * "oic" for a Director II who heads a second office at the same time; here that is "Concurrent".
     */
    private const CAPACITY_LABELS = ['oic' => 'Concurrent', 'caretaker' => 'Caretaker'];

    /** Who acts on an appointment that PSED processes. */
    private const DIRECTOR_POSITIONS = ['director iv', 'director iii'];

    /** The kinds of office CARES lists people from (IMIS's unit types). */
    private const OFFICE_TYPES = ['field_office', 'satellite_office'];

    /** Whether CARES has been told where IMIS is and given the token. */
    public function configured(): bool
    {
        return config('services.imis.api_url') !== null && config('services.imis.api_token') !== null;
    }

    /**
     * @return array{employees: Collection<int, array<string, mixed>>, heads: array<string, array<string, mixed>>}
     *
     * @throws DirectoryUnavailable
     */
    public function load(): array
    {
        $fresh = Cache::get(self::FRESH);
        if ($fresh !== null) {
            return $this->hydrate($fresh);
        }

        try {
            $raw = $this->fetch();
        } catch (DirectoryUnavailable $e) {
            $stale = Cache::get(self::STALE);

            if ($stale !== null) {
                return $this->hydrate($stale);
            }

            throw $e;
        }

        Cache::put(self::FRESH, $raw, self::FRESH_SECONDS);
        Cache::put(self::STALE, $raw, 86400);

        return $this->hydrate($raw);
    }

    /** Throws the cached answers away, so the next read asks IMIS. */
    public function forget(): void
    {
        Cache::forget(self::FRESH);
        Cache::forget(self::STALE);
    }

    /** @return Collection<int, array<string, mixed>> every active employee */
    public function employees(): Collection
    {
        return $this->load()['employees'];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->load()['employees']->get($id);
    }

    /**
     * The employees of the Field and Satellite Offices only: what CARES offers wherever it lists
     * people (the action officer). Regional support units (PSED, the divisions, the Regional
     * Director's office) are not listed.
     *
     * @param  ?string  $onlyOffice  an IMIS office code to limit the list to one office (a Field Office
     *                               user's own jurisdiction); null lists every Field Office (administrators)
     * @return Collection<int, array<string, mixed>>
     */
    public function fieldOfficeEmployees(?string $onlyOffice = null): Collection
    {
        $people = collect();

        foreach ($this->fieldOfficeRoster($onlyOffice) as $office) {
            foreach ($office['people'] as $id => $person) {
                $people->put($id, $person);   // someone who belongs to two offices is one person
            }
        }

        return $people;
    }

    /**
     * Each Field and Satellite Office with the people who belong to it: the employees IMIS places there,
     * and whoever IMIS shows heading it by designation, so a Director II who holds a second office
     * concurrently appears in that office too, not only in the one they work in.
     *
     * @param  ?string  $onlyOffice  limit the roster to one office (a Field Office user's own jurisdiction)
     * @return array<string, array{name: string, people: Collection<int, array<string, mixed>>}> by IMIS office code
     */
    public function fieldOfficeRoster(?string $onlyOffice = null): array
    {
        $directory = $this->load();
        $roster = [];

        foreach ($directory['employees'] as $employee) {
            $code = $employee['office_code'] ?? null;

            if ($code !== null && $this->inFieldOffice($employee) && ($onlyOffice === null || $code === $onlyOffice)) {
                $roster[$code]['name'] ??= (string) $employee['office_name'];
                $roster[$code]['people'][$employee['id']] = $employee;
            }
        }

        // Whoever IMIS shows heading an office by designation (a concurrent Director II, a caretaker) belongs to it.
        foreach ($directory['heads'] as $code => $head) {
            $employee = $directory['employees']->get($head['user_id']);

            if ($head['capacity'] === null || $employee === null || ! in_array($head['office_type'] ?? null, self::OFFICE_TYPES, true)
                || ($onlyOffice !== null && $code !== $onlyOffice)) {
                continue;
            }

            $roster[$code]['name'] ??= (string) ($head['office_name'] ?? $code);
            $roster[$code]['people'][$employee['id']] = $employee;
        }

        return collect($roster)
            ->map(fn (array $office) => ['name' => $office['name'], 'people' => collect($office['people'])])
            ->all();
    }

    /**
     * One employee, if they work in a Field or Satellite Office (and, when given, in that office).
     *
     * @return array<string, mixed>|null
     */
    public function findInFieldOffice(int $id, ?string $onlyOffice = null): ?array
    {
        return $this->fieldOfficeEmployees($onlyOffice)->get($id);
    }

    /**
     * The people of one office, by its IMIS code. With `$withoutHead`, only its staff: the office's
     * head (the Director II, or whoever IMIS shows heading it) and any Director II are left out, since
     * they approve what the staff prepare.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function staffOf(string $officeCode, bool $withoutHead = false): Collection
    {
        $people = $this->employees()->filter(fn (array $e) => $e['office_code'] === $officeCode);

        if ($withoutHead) {
            $headId = $this->headOf($officeCode)['employee']['id'] ?? null;
            $people = $people->filter(fn (array $e) => $e['id'] !== $headId && ! $this->isDirectorII($e));
        }

        return $people->values();
    }

    /**
     * The Director IV and Director III of the Office of the Regional Director: they act on the
     * appointments PSED processes (a Field Office's own staff act on the ones the Field Office
     * processes). Only these two positions of that office are listed, not the rest of its people.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function directors(): Collection
    {
        return $this->employees()->filter(fn (array $e) => $this->isRegionalDirector($e));
    }

    /** @return array<string, mixed>|null the employee, if they are the regional office's Director IV or III */
    public function findDirector(int $id): ?array
    {
        $employee = $this->find($id);

        return $employee !== null && $this->isRegionalDirector($employee) ? $employee : null;
    }

    /**
     * People as a list is read: the highest salary grade first, then by name; a position with no
     * grade in IMIS last.
     *
     * @param  Collection<int, array<string, mixed>>  $people
     * @return Collection<int, array<string, mixed>>
     */
    public static function byGrade(Collection $people): Collection
    {
        return $people->sort(function (array $a, array $b) {
            return [$b['salary_grade'] ?? -1, mb_strtolower((string) $a['name'])] <=> [$a['salary_grade'] ?? -1, mb_strtolower((string) $b['name'])];
        })->values();
    }
    /** The one Director IV or Director III a written name stands for (see match()). @return array<string, mixed>|null */
    public function matchDirector(string $name): ?array
    {
        return $this->matchIn($this->directors(), $name);
    }

    /**
     * Who heads the office today and in what title: the Director II, or while
     * designated the Officer-in-Charge or Caretaker. Null when IMIS shows no head.
     *
     * @return array{employee: array<string, mixed>, capacity: ?string, capacity_label: ?string, title: string, is_director_ii: bool}|null
     */
    public function headOf(string $officeCode): ?array
    {
        $directory = $this->load();
        $head = $directory['heads'][$officeCode] ?? null;
        $employee = $head ? $directory['employees']->get($head['user_id']) : null;

        if ($employee === null) {
            return null;
        }

        $capacity = $head['capacity'];
        $label = $capacity === null ? null : (self::CAPACITY_LABELS[$capacity] ?? null);
        $position = trim((string) $employee['position']);

        return [
            'employee' => $employee,
            'capacity' => $capacity,
            'capacity_label' => $label,
            // Reports are signed by position alone ("Director II"), whether the head heads this office
            // regularly or concurrently with another. The capacity is shown where people choose or review,
            // never printed.
            'title' => $position,
            // A Director II heads the office under the SG 26 rule, concurrently or not. Anyone else who
            // heads it (a caretaker, or an officer-in-charge below Director II) is the SG 22 case.
            'is_director_ii' => $this->isDirectorII($employee),
        ];
    }

    /**
     * The one employee a written name stands for, or null when none or several do.
     * "Ryan Jose M. Estoque", "RYAN JOSE ESTOQUE" and "ryan jose m estoque" are the same person.
     *
     * Only Field and Satellite Office employees are considered, and with `$onlyOffice` only that office's.
     *
     * @return array<string, mixed>|null
     */
    public function match(string $name, ?string $onlyOffice = null): ?array
    {
        return $this->matchIn($this->fieldOfficeEmployees($onlyOffice), $name);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $pool
     * @return array<string, mixed>|null
     */
    private function matchIn(Collection $pool, string $name): ?array
    {
        $wanted = $this->normalise($name);
        if ($wanted === '') {
            return null;
        }

        $found = $pool->filter(fn (array $e) => $this->normalise((string) $e['name']) === $wanted);

        return $found->count() === 1 ? $found->first() : null;
    }

    private function position(array $employee): string
    {
        return mb_strtolower(trim((string) ($employee['position'] ?? '')));
    }

    private function isRegionalDirector(array $employee): bool
    {
        return ($employee['office_code'] ?? null) === config('psed.imis.regional_director_unit_code')
            && in_array($this->position($employee), self::DIRECTOR_POSITIONS, true);
    }

    private function isDirectorII(array $employee): bool
    {
        return $this->position($employee) === 'director ii';
    }

    /** A name as the reports sign it: upper case, middle initial kept ("RYAN JOSE M. ESTOQUE"). */
    public static function signatureName(array $employee): string
    {
        return mb_strtoupper((string) $employee['name']);
    }

    /** "Ryan Jose M. Estoque · Senior Human Resource Specialist", as a choice in a list. */
    public static function label(array $employee): string
    {
        return trim($employee['name'].(filled($employee['position']) ? ' · '.$employee['position'] : ''));
    }

    // ------------------------------------------------------------------ internals

    /** @return array<string, mixed> IMIS's answer, as received */
    private function fetch(): array
    {
        if (! $this->configured()) {
            throw new DirectoryUnavailable('The IMIS directory is not set up (IMIS_API_URL and IMIS_API_TOKEN).');
        }

        try {
            $response = Http::withToken((string) config('services.imis.api_token'))
                ->acceptJson()->timeout(8)
                ->get(rtrim((string) config('services.imis.api_url'), '/').'/api/v1/cares/employees');
        } catch (Throwable) {
            throw new DirectoryUnavailable('IMIS could not be reached, so the list of employees is not available right now.');
        }

        $data = $response->json('data');
        $heads = $response->json('heads');

        if (! $response->successful() || ! is_array($data) || ! is_array($heads)) {
            throw new DirectoryUnavailable('IMIS did not give the list of employees (check the CARES token in IMIS).');
        }

        return ['data' => $data, 'heads' => $heads];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{employees: Collection<int, array<string, mixed>>, heads: array<string, array<string, mixed>>}
     */
    private function hydrate(array $raw): array
    {
        return [
            'employees' => collect($raw['data'])->keyBy('id'),
            'heads' => collect($raw['heads'])->keyBy('office_code')->all(),
        ];
    }

    /** Whether the person works in a Field or Satellite Office (not a regional support unit). */
    private function inFieldOffice(array $employee): bool
    {
        return in_array($employee['office_type'] ?? null, self::OFFICE_TYPES, true);
    }

    /**
     * What two spellings of one name share: lower case, no punctuation, no middle initials, and
     * in alphabetical order, so "Estoque, Ryan Jose" and "Ryan Jose M. Estoque" are the same name.
     */
    private function normalise(string $name): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(trim($name)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_filter($words, fn (string $w) => mb_strlen($w) > 1));
        sort($words);

        return implode(' ', $words);
    }
}
