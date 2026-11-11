<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\FieldOffice;
use App\Models\ReferenceValue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * The PSED-wide list of agencies, each within one Field Office's jurisdiction.
 * It is managed in PSED (the Directory of Government Agencies) and brought in by
 * `php artisan agencies:sync`; database/data/agencies.json is the last synced copy.
 */
class AgencyList
{
    public function jsonPath(): string
    {
        return database_path('data/agencies.json');
    }

    /**
     * The agencies in the PSED Directory of Government Agencies (not the soft-deleted ones).
     *
     * @return list<array{name: string, sector: string, field_office: string}>
     *
     * @throws \RuntimeException when the directory cannot be read or has no agencies
     */
    public function fromDirectory(): array
    {
        $table = config('psed.agency_directory.table');

        try {
            $rows = DB::connection('psed_directory')->table($table)
                ->whereNull('deleted_at')
                ->get(['field_office', 'sector', 'agency'])
                ->map(fn ($r) => ['name' => $r->agency, 'sector' => $r->sector, 'field_office' => $r->field_office]);
        } catch (Throwable $e) {
            throw new RuntimeException('Could not read the PSED directory ('.config('database.connections.psed_directory.database').".{$table}): ".$e->getMessage(), previous: $e);
        }

        $agencies = $this->normalize($rows);

        if ($agencies === []) {
            throw new RuntimeException('The PSED directory has no agencies; nothing was changed.');
        }

        return $agencies;
    }

    /**
     * Renames an agency here and in the PSED directory, so the next sync keeps the new name. Appointments
     * already saved under the old name follow it. All or nothing: if the directory has no such agency (or
     * cannot be reached) nothing here changes either.
     *
     * @return array{appointments: int, directory: int} the appointments renamed and the directory entries renamed
     *
     * @throws RuntimeException
     */
    public function rename(ReferenceValue $agency, string $name): array
    {
        $old = $agency->value;
        $name = preg_replace('/\s+/', ' ', trim($name));

        return DB::transaction(function () use ($agency, $old, $name) {
            $appointments = Appointment::where('agency', $old)->update(['agency' => $name]);
            $agency->update(['value' => $name]);

            $directory = $this->renameInDirectory($old, $name);

            if ($directory === 0) {
                throw new RuntimeException("\"{$old}\" is not in the PSED directory (sync first, or it was already renamed there), so nothing was changed.");
            }

            $snapshot = $this->fromJson();
            if ($snapshot !== []) {
                $this->saveJson(collect($snapshot)->map(fn ($a) => $a['name'] === $old ? [...$a, 'name' => $name] : $a)->all());
            }

            return ['appointments' => $appointments, 'directory' => $directory];
        });
    }

    /** Renames the agency's entry (or entries) in the PSED directory. @return int how many were renamed */
    public function renameInDirectory(string $old, string $new): int
    {
        try {
            return DB::connection('psed_directory')->table(config('psed.agency_directory.table'))
                ->whereNull('deleted_at')
                ->whereRaw("TRIM(REGEXP_REPLACE(agency, '[[:space:]]+', ' ')) = ?", [$old])
                ->update(['agency' => $new, 'updated_at' => now()]);
        } catch (Throwable $e) {
            throw new RuntimeException('Could not update the PSED directory: '.$e->getMessage(), previous: $e);
        }
    }

    /** @return list<array{name: string, sector: string, field_office: string}> */
    public function fromJson(): array
    {
        return json_decode((string) @file_get_contents($this->jsonPath()), true) ?: [];
    }

    /** @param list<array{name: string, sector: string, field_office: string}> $agencies */
    public function saveJson(array $agencies): void
    {
        file_put_contents($this->jsonPath(), json_encode($agencies, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Tidies the rows of a directory: trimmed names, one entry per name (ignoring case).
     *
     * @param  iterable<array<string, mixed>>  $rows  with name, sector, field_office
     * @return list<array{name: string, sector: string, field_office: string}>
     */
    public function normalize(iterable $rows): array
    {
        return collect($rows)
            ->map(fn ($r) => [
                'name' => preg_replace('/\s+/', ' ', trim((string) ($r['name'] ?? ''))),
                'sector' => trim((string) ($r['sector'] ?? '')),
                'field_office' => trim((string) ($r['field_office'] ?? '')),
            ])
            ->filter(fn ($r) => $r['name'] !== '')
            ->unique(fn ($r) => mb_strtolower($r['name']))
            ->sortBy([['field_office', 'asc'], ['name', 'asc']])
            ->values()
            ->all();
    }

    /**
     * Makes the agency reference list match the given agencies.
     *
     * @param  list<array{name: string, sector: string, field_office: string}>  $agencies
     * @return array{added: int, moved: int, removed: int, unchanged: int, added_names: list<string>, moved_names: list<string>, removed_names: list<string>, no_office: list<string>, orphaned: Collection<string, int>}
     */
    public function sync(array $agencies, bool $dryRun = false): array
    {
        // The directory says "CSC Field Office - Leyte I"; the system says "Field Office-Leyte I".
        $key = fn (string $n) => preg_replace('/\s*-\s*/', '-', preg_replace('/^CSC\s+/i', '', trim($n)));
        $offices = FieldOffice::pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [$key($name) => $id]);

        $wanted = collect($agencies)->mapWithKeys(fn ($a) => [
            $a['name'] => $offices[$key($a['field_office'])] ?? null,
        ]);

        $current = ReferenceValue::where('type', 'agency')->get()->keyBy('value');

        $added = $wanted->keys()->diff($current->keys());
        $removed = $current->keys()->diff($wanted->keys());
        $moved = $wanted->filter(fn ($officeId, $name) => $current->has($name) && $current[$name]->field_office_id !== $officeId);

        if (! $dryRun) {
            $now = now();
            $rows = $wanted->map(fn ($officeId, $name) => [
                'type' => 'agency', 'value' => $name, 'field_office_id' => $officeId, 'created_at' => $now, 'updated_at' => $now,
            ])->values();

            foreach ($rows->chunk(500) as $chunk) {
                ReferenceValue::upsert($chunk->all(), ['type', 'value'], ['field_office_id', 'updated_at']);
            }

            if ($removed->isNotEmpty()) {
                ReferenceValue::where('type', 'agency')->whereIn('value', $removed->all())->delete();
            }
        }

        // Saved appointments that name an agency the list does not have (renamed or removed in the directory).
        $orphaned = Appointment::query()->whereNotIn('agency', $wanted->keys()->all())
            ->selectRaw('agency, count(*) as total')->groupBy('agency')->orderBy('agency')->pluck('total', 'agency');

        return [
            'added' => $added->count(),
            'moved' => $moved->count(),
            'removed' => $removed->count(),
            'unchanged' => $wanted->count() - $added->count() - $moved->count(),
            'added_names' => $added->values()->all(),
            'moved_names' => $moved->keys()->all(),
            'removed_names' => $removed->values()->all(),
            'no_office' => $wanted->filter(fn ($id) => $id === null)->keys()->all(),
            'orphaned' => $orphaned,
        ];
    }
}
