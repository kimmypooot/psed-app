<?php

namespace App\Http\Controllers;

use App\Enums\HeadType;
use App\Models\AuditLog;
use App\Models\FieldOffice;
use App\Services\DirectoryUnavailable;
use App\Services\ImisDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who heads each Field Office, and who signs its reports. The head decides
 * where appointments are routed (the flow chart's step 4), so changing it
 * re-routes the office's appointments (see FieldOffice::booted()).
 *
 * The people come from IMIS: "Prepared by" is one of the office's staff as
 * IMIS lists them (chosen here), and "Approved / noted by" is the office's head
 * in IMIS, the Director II or, while designated, the Officer-in-Charge or
 * Caretaker (shown here, never typed).
 */
class FieldOfficeController extends Controller
{
    public function __construct(private readonly ImisDirectory $directory) {}

    public function index(): Response
    {
        $this->authorize('manage-offices');

        $offices = FieldOffice::withCount('appointments')->orderBy('name')->get();

        try {
            $employees = $this->directory->employees();
            $available = true;
            $message = null;
        } catch (DirectoryUnavailable $e) {
            $employees = collect();
            $available = false;
            $message = $e->getMessage();
        }

        return Inertia::render('Settings/FieldOffices', [
            'offices' => $offices->map(fn (FieldOffice $o) => $this->present($o, $available, $employees)),
            'headTypes' => collect(HeadType::cases())->map(fn (HeadType $h) => [
                'value' => $h->value,
                'label' => $h->label(),
                'psed_from_sg' => $h->psedThreshold(),
            ])->all(),
            'directory' => [
                'available' => $available,
                'message' => $message,
                // Who can prepare a report: each office's staff, never its head (the head approves it).
                'staff' => $available
                    ? $offices->flatMap(fn (FieldOffice $o) => $this->directory->staffOf($o->imis_code, withoutHead: true))
                        ->map(fn (array $e) => ['id' => $e['id'], 'name' => $e['name'], 'position' => $e['position'], 'office_code' => $e['office_code']])
                        ->values()->all()
                    : [],
            ],
        ]);
    }

    public function update(Request $request, FieldOffice $office): RedirectResponse
    {
        $this->authorize('manage-offices');

        $data = $request->validate([
            'head_type' => ['required', Rule::enum(HeadType::class)],
            'location' => ['nullable', 'string', 'max:255'],
            'prepared_by_imis_id' => ['nullable', 'integer'],
        ]);

        $before = $office->only('head_type', 'location', 'prepared_by_imis_id', 'prepared_by_name', 'approved_by_name');
        $before['head_type'] = $office->head_type->value;

        $changes = ['head_type' => $data['head_type'], 'location' => $data['location'] ?? null];

        // Who prepares the reports: one of this office's staff in IMIS. Only looked up when it is sent and
        // differs from what is saved; leaving it out leaves it alone.
        if (array_key_exists('prepared_by_imis_id', $data) && $data['prepared_by_imis_id'] !== $office->prepared_by_imis_id) {
            $changes += $this->preparedBy($office, $data['prepared_by_imis_id']);
        }

        // Keep the saved approver in step with IMIS's head, for the day IMIS cannot be reached.
        try {
            $head = $this->directory->headOf($office->imis_code);
            if ($head !== null) {
                $changes['approved_by_name'] = ImisDirectory::signatureName($head['employee']);
                $changes['approved_by_title'] = $head['title'];
            }
        } catch (DirectoryUnavailable) {
            // nothing to refresh from
        }

        $office->update($changes);

        AuditLog::record('field_office.update', $office, before: $before, after: [
            ...$office->only('head_type', 'location', 'prepared_by_imis_id', 'prepared_by_name', 'approved_by_name'),
            'head_type' => $office->head_type->value,
        ]);

        return back()->with('status', "{$office->name} was updated.");
    }

    // ------------------------------------------------------------------ helpers

    /**
     * The columns that record the preparer chosen from IMIS.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function preparedBy(FieldOffice $office, ?int $id): array
    {
        if ($id === null) {
            return ['prepared_by_imis_id' => null, 'prepared_by_name' => null, 'prepared_by_title' => null];
        }

        try {
            $person = $this->directory->find($id);
        } catch (DirectoryUnavailable $e) {
            throw ValidationException::withMessages(['prepared_by_imis_id' => $e->getMessage()]);
        }

        if ($person === null || $person['office_code'] !== $office->imis_code) {
            throw ValidationException::withMessages(['prepared_by_imis_id' => "Choose someone from {$office->name}'s staff in IMIS."]);
        }

        // The head approves the reports, so cannot also be the one who prepares them.
        if (! $this->directory->staffOf($office->imis_code, withoutHead: true)->contains('id', $id)) {
            throw ValidationException::withMessages(['prepared_by_imis_id' => "{$person['name']} heads this office and approves its reports. Choose one of its staff to prepare them."]);
        }

        return [
            'prepared_by_imis_id' => $id,
            'prepared_by_name' => ImisDirectory::signatureName($person),
            'prepared_by_title' => $person['position'],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $employees
     * @return array<string, mixed>
     */
    private function present(FieldOffice $o, bool $available, $employees): array
    {
        $head = null;
        if ($available) {
            try {
                $head = $this->directory->headOf($o->imis_code);
            } catch (DirectoryUnavailable) {
                $head = null;
            }
        }

        $person = $o->prepared_by_imis_id ? $employees->get($o->prepared_by_imis_id) : null;

        return [
            ...$o->only('id', 'name', 'imis_code', 'location'),
            'head_type' => $o->head_type->value,
            'psed_from_sg' => $o->head_type->psedThreshold(),
            'appointments_count' => $o->appointments_count,
            'prepared_by_imis_id' => $o->prepared_by_imis_id,
            // IMIS's current answer when it has one, otherwise what was saved.
            'prepared_by_name' => $person ? ImisDirectory::signatureName($person) : $o->prepared_by_name,
            'prepared_by_title' => $person['position'] ?? $o->prepared_by_title,
            'approved_by_name' => $head ? ImisDirectory::signatureName($head['employee']) : $o->approved_by_name,
            'approved_by_title' => $head['title'] ?? $o->approved_by_title,
            'head' => $head ? [
                'name' => ImisDirectory::signatureName($head['employee']),
                'title' => $head['title'],
                'capacity' => $head['capacity'],
                'capacity_label' => $head['capacity_label'],
            ] : null,
            // The routing rule that goes with who heads the office (flow chart, step 4): a Director II, even
            // one heading this office concurrently with another, is the SG 26 case; anyone else is SG 22.
            'suggested_head_type' => $head ? ($head['is_director_ii'] ? HeadType::DirectorII->value : HeadType::OicSg22->value) : null,
        ];
    }
}
