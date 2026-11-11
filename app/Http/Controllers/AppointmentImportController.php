<?php

namespace App\Http\Controllers;

use App\Enums\Processor;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\FieldOffice;
use App\Models\User;
use App\Services\AppointmentImporter;
use App\Services\AppointmentRouter;
use App\Services\ImportException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Bringing appointments in from the MOA form, in two steps so nothing is saved
 * by surprise: preview() reads and checks the file and reports what it found;
 * commit() imports the rows that passed, from the same stored file (nothing the
 * browser sends back is trusted). Same rule as encoding: Field Office staff
 * import into their own office, PSED into the office named per row or chosen.
 */
class AppointmentImportController extends Controller
{
    private const CACHE = 'appointment-import:';

    /** The blank MOA form to copy appointments into. */
    public function template(AppointmentImporter $importer): BinaryFileResponse|RedirectResponse
    {
        $this->authorize('create', Appointment::class);

        try {
            $file = $importer->blankForm();
        } catch (RuntimeException $e) {
            report($e);

            return redirect()->route('appointments.index')->with('error', $e->getMessage());
        }

        return response()->download($file['path'], $file['name'])->deleteFileAfterSend(true);
    }

    /** Step 1: read and check the file. Saves nothing. */
    public function preview(Request $request, AppointmentImporter $importer, AppointmentRouter $router): JsonResponse
    {
        $this->authorize('create', Appointment::class);
        $user = $request->user();

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
            'field_office_id' => $user->seesAllOffices() ? ['required', 'regex:/^(file|\d+)$/'] : ['nullable'],
        ], [
            'file.mimes' => 'Upload an Excel (.xlsx) file: the MOA form.',
            'file.max' => 'The file may not be larger than 10 MB.',
        ]);

        $choice = $user->seesAllOffices() ? (string) $request->input('field_office_id') : null;
        $office = $this->officeFor($user, $choice);

        $this->pruneOldFiles();
        $stored = $request->file('file')->store('imports', 'local');

        try {
            $analysis = $importer->analyse(Storage::disk('local')->path($stored), $user, $office);
        } catch (ImportException $e) {
            Storage::disk('local')->delete($stored);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $token = (string) Str::uuid();
        Cache::put(self::CACHE.$token, [
            'user' => $user->id,
            'path' => $stored,
            'name' => mb_substr($request->file('file')->getClientOriginalName(), 0, 200),
            'office' => $choice,
        ], now()->addHours(2));

        return response()->json([
            'token' => $token,
            'file_name' => $request->file('file')->getClientOriginalName(),
            'counts' => $analysis['counts'],
            'problems' => $this->problems($analysis['rows']),
            'sample' => $this->sample($analysis['rows'], $router),
        ]);
    }

    /** Step 2: import the rows that passed. */
    public function commit(Request $request, AppointmentImporter $importer): RedirectResponse
    {
        $this->authorize('create', Appointment::class);
        $user = $request->user();

        $request->validate(['token' => ['required', 'uuid']]);

        $entry = Cache::get(self::CACHE.$request->input('token'));

        // Someone else's token, a used one or an old one: the file is gone or not theirs.
        if (! is_array($entry) || $entry['user'] !== $user->id || ! Storage::disk('local')->exists($entry['path'])) {
            return redirect()->route('appointments.index')->with('error', 'This import has expired. Please choose the file again.');
        }

        $office = $this->officeFor($user, $entry['office']);

        try {
            $result = $importer->import(Storage::disk('local')->path($entry['path']), $user, $office);
        } catch (ImportException $e) {
            return redirect()->route('appointments.index')->with('error', $e->getMessage());
        }

        Storage::disk('local')->delete($entry['path']);
        Cache::forget(self::CACHE.$request->input('token'));

        AuditLog::record('appointment.import', after: [
            'file' => $entry['name'],
            'imported' => $result['imported'],
            'duplicates_skipped' => $result['duplicates'],
            'rows_with_errors' => $result['invalid'],
            'office' => $office?->imis_code ?? 'as named in the file',
        ]);

        $message = $result['imported'] === 0
            ? "Nothing was imported from {$entry['name']}."
            : "Imported {$result['imported']} appointment".($result['imported'] === 1 ? '' : 's')." from {$entry['name']}.";
        $left = $result['rows'] - $result['imported'];
        if ($left > 0) {
            $message .= " {$left} row".($left === 1 ? ' was' : 's were').' left out (errors or duplicates).';
        }

        return redirect()->route('appointments.index')->with('status', $message);
    }

    // ------------------------------------------------------------------ helpers

    /** Field Office staff always import into their own office; PSED picks one, or reads it per row ("file"). */
    private function officeFor(User $user, ?string $choice): ?FieldOffice
    {
        return match (true) {
            ! $user->seesAllOffices() => $user->fieldOffice,
            $choice === 'file' || $choice === null => null,
            default => FieldOffice::findOrFail($choice),
        };
    }

    /**
     * What is wrong, row by row: errors first, then duplicates, then warnings. Capped, because
     * a file full of problems does not need 2,000 lines on screen.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function problems(array $rows, int $limit = 100): array
    {
        $rank = fn (array $r) => $r['errors'] !== [] ? 0 : ($r['duplicate'] ? 1 : 2);

        return collect($rows)
            ->filter(fn ($r) => $r['errors'] !== [] || $r['duplicate'] || $r['warnings'] !== [])
            ->sortBy($rank)
            ->take($limit)
            ->map(fn ($r) => [
                'sheet' => $r['sheet'],
                'row' => $r['row'],
                'name' => $r['name'],
                'kind' => $r['errors'] !== [] ? 'error' : ($r['duplicate'] ? 'duplicate' : 'warning'),
                'messages' => [...$r['errors'], ...$r['warnings']],
            ])->values()->all();
    }

    /**
     * A few of the rows that will be imported, with who will process each.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sample(array $rows, AppointmentRouter $router, int $limit = 6): array
    {
        return collect($rows)->filter(fn ($r) => $r['ready'])->take($limit)->map(function ($r) use ($router) {
            $processor = $router->route($r['office'], (int) $r['data']['salary_grade']);

            return [
                'name' => $r['name'],
                'position' => $r['data']['position'],
                'salary_grade' => $r['data']['salary_grade'],
                'date_received' => $r['data']['date_received'],
                'office' => $r['office']->name,
                'processed_by' => $processor === Processor::Psed ? 'PSED' : $r['office']->name,
            ];
        })->values()->all();
    }

    /** Uploaded files that were never confirmed are not kept: anything older than three hours goes. */
    private function pruneOldFiles(): void
    {
        $disk = Storage::disk('local');

        foreach ($disk->files('imports') as $file) {
            if ($disk->lastModified($file) < now()->subHours(3)->getTimestamp()) {
                $disk->delete($file);
            }
        }
    }
}
