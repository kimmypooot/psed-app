<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\FieldOffice;
use App\Models\ReferenceValue;
use App\Services\AgencyList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The agency list. It is kept in PSED (the Directory of Government Agencies) and
 * brought in here, so this page only shows what the system has and syncs it:
 * check first (changes nothing), then sync.
 */
class AgencySettingsController extends Controller
{
    /** How many agency names a result lists before saying "and n more". */
    private const LISTED = 15;

    public function __construct(private readonly AgencyList $agencies) {}

    public function index(): Response
    {
        $this->authorize('manage-offices');

        $counts = ReferenceValue::where('type', 'agency')->selectRaw('field_office_id, count(*) as total')->groupBy('field_office_id')->pluck('total', 'field_office_id');

        $sectors = collect($this->agencies->fromJson())->pluck('sector', 'name');
        $byOffice = ReferenceValue::where('type', 'agency')->orderBy('value')->get(['id', 'value', 'field_office_id'])->groupBy('field_office_id');

        $used = Appointment::selectRaw('agency, count(*) as total')->groupBy('agency')->pluck('total', 'agency');

        $last = AuditLog::with('user:id,name')->where('action', 'agency.sync')->latest('id')->first();

        return Inertia::render('Settings/Agencies', [
            'offices' => FieldOffice::orderBy('name')->get(['id', 'name'])->map(fn (FieldOffice $o) => [
                'id' => $o->id,
                'name' => $o->name,
                'agencies' => (int) ($counts[$o->id] ?? 0),
                'list' => ($byOffice[$o->id] ?? collect())->map(fn ($r) => ['id' => $r->id, 'name' => $r->value, 'sector' => $sectors[$r->value] ?? null, 'appointments' => (int) ($used[$r->value] ?? 0)])->values(),
            ]),
            'total' => (int) $counts->sum(),
            'unassigned' => ($byOffice[''] ?? collect())->pluck('value')->values(),
            'without_office' => ReferenceValue::where('type', 'agency')->whereNull('field_office_id')->count(),
            'last_sync' => $last ? [
                'at' => $last->created_at->toIso8601String(),
                'by' => $last->user?->name,
                'summary' => $last->after,
            ] : null,
            'directory_url' => config('psed.agency_directory.url'),
        ]);
    }

    /** Renames an agency here and in the PSED directory, so the two stay the same list. */
    public function rename(Request $request, ReferenceValue $agency): RedirectResponse
    {
        $this->authorize('manage-offices');
        abort_unless($agency->type === 'agency', 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('reference_values', 'value')->where('type', 'agency')->ignore($agency->id)],
        ], ['name.unique' => 'Another agency already has this name.']);

        $old = $agency->value;
        $name = preg_replace('/\s+/', ' ', trim($data['name']));

        if ($name === $old) {
            return back();
        }

        try {
            $done = $this->agencies->rename($agency, $name);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['name' => $e->getMessage()]);
        }

        AuditLog::record('agency.rename', before: ['name' => $old], after: ['name' => $name, 'appointments' => $done['appointments'], 'directory' => $done['directory']]);

        return back()->with('status', "Renamed to \"{$name}\" here and in the PSED directory.");
    }

    public function sync(Request $request): RedirectResponse
    {
        $this->authorize('manage-offices');

        $dry = $request->boolean('dry_run');

        try {
            $agencies = $this->agencies->fromDirectory();
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $result = $this->agencies->sync($agencies, $dry);

        if (! $dry) {
            $this->agencies->saveJson($agencies);

            AuditLog::record('agency.sync', after: [
                'agencies' => count($agencies),
                'added' => $result['added'],
                'moved' => $result['moved'],
                'removed' => $result['removed'],
            ]);
        }

        $names = fn (array $list) => ['names' => array_slice($list, 0, self::LISTED), 'more' => max(0, count($list) - self::LISTED)];

        return back()->with('agency_sync', [
            'dry_run' => $dry,
            'total' => count($agencies),
            'added' => $result['added'],
            'moved' => $result['moved'],
            'removed' => $result['removed'],
            'unchanged' => $result['unchanged'],
            'added_list' => $names($result['added_names']),
            'moved_list' => $names($result['moved_names']),
            'removed_list' => $names($result['removed_names']),
            'no_office' => $names($result['no_office']),
            'orphaned' => $result['orphaned']->map(fn ($total, $agency) => ['agency' => $agency, 'total' => $total])->values()->take(self::LISTED),
            'orphaned_total' => $result['orphaned']->count(),
        ])->with('status', $dry ? null : 'The agency list was synced from PSED.');
    }
}
