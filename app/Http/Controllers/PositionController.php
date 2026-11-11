<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\ReferenceValue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The position titles suggested on the appointment form (about 2,000, from the MOA template's RefData
 * list). PSED administrators add, rename and remove them. An appointment keeps the title it was encoded
 * with: removing a title from the list does not touch it, and a rename can carry the appointments along.
 */
class PositionController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $this->authorize('manage-offices');

        $search = trim((string) ($request->validate(['search' => ['nullable', 'string', 'max:100']])['search'] ?? ''));

        $positions = ReferenceValue::where('type', 'position')
            ->when($search !== '', fn ($q) => $q->where('value', 'like', '%'.$search.'%')->orderByRaw('value like ? desc', [$search.'%']))
            ->orderBy('value')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $used = Appointment::whereIn('position', $positions->pluck('value'))
            ->selectRaw('position, count(*) as total')->groupBy('position')->pluck('total', 'position');

        return Inertia::render('Settings/Positions', [
            'positions' => [
                'data' => $positions->getCollection()->map(fn (ReferenceValue $p) => [
                    'id' => $p->id,
                    'name' => $p->value,
                    'appointments' => (int) ($used[$p->value] ?? 0),
                ])->values(),
                'current_page' => $positions->currentPage(),
                'last_page' => $positions->lastPage(),
                'total' => $positions->total(),
                'prev_page_url' => $positions->previousPageUrl(),
                'next_page_url' => $positions->nextPageUrl(),
            ],
            'filters' => ['search' => $search],
            'overall' => ReferenceValue::where('type', 'position')->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage-offices');

        $name = $this->validatedName($request);
        $position = ReferenceValue::create(['type' => 'position', 'value' => $name]);

        AuditLog::record('position.create', after: ['name' => $name]);

        return back()->with('status', "\"{$name}\" was added to the positions.");
    }

    public function update(Request $request, ReferenceValue $position): RedirectResponse
    {
        $this->authorize('manage-offices');
        abort_unless($position->type === 'position', 404);

        $name = $this->validatedName($request, $position);
        $old = $position->value;

        if ($name === $old) {
            return back();
        }

        $renamed = 0;
        $position->update(['value' => $name]);

        if ($request->boolean('update_appointments')) {
            $renamed = Appointment::where('position', $old)->update(['position' => $name]);
        }

        AuditLog::record('position.update', before: ['name' => $old], after: ['name' => $name, 'appointments' => $renamed]);

        return back()->with('status', "Renamed to \"{$name}\"".($renamed ? " and on {$renamed} saved appointment".($renamed === 1 ? '' : 's') : '').'.');
    }

    public function destroy(ReferenceValue $position): RedirectResponse
    {
        $this->authorize('manage-offices');
        abort_unless($position->type === 'position', 404);

        $name = $position->value;
        $position->delete();

        AuditLog::record('position.delete', before: ['name' => $name]);

        return back()->with('status', "\"{$name}\" was removed from the positions.");
    }

    private function validatedName(Request $request, ?ReferenceValue $ignore = null): string
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('reference_values', 'value')->where('type', 'position')->ignore($ignore?->id)],
        ], ['name.unique' => 'This position is already on the list.']);

        return preg_replace('/\s+/', ' ', trim($data['name']));
    }
}
