<?php

namespace App\Http\Controllers;

use App\Enums\Processor;
use App\Http\Requests\AppointmentRequest;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\FieldOffice;
use App\Models\User;
use App\Services\AppointmentRouter;
use App\Services\DirectoryUnavailable;
use App\Services\ImisDirectory;
use App\Services\LookupService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AppointmentController extends Controller
{
    private const SORTS = [
        'date_received' => 'date_received',
        'name' => 'last_name',
        'agency' => 'agency',
        'salary_grade' => 'salary_grade',
    ];

    public function __construct(
        private readonly LookupService $lookups,
        private readonly AppointmentRouter $router,
        private readonly ImisDirectory $directory,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Appointment::class);
        $user = $request->user();

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'sg' => ['nullable', 'integer', 'between:1,33'],
            'agency' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'processor' => ['nullable', 'in:field_office,psed'],
            'status' => ['nullable', 'in:pending,acted'],
            'office' => ['nullable', 'integer'],
            'sort' => ['nullable', 'in:'.implode(',', array_keys(self::SORTS))],
            'dir' => ['nullable', 'in:asc,desc'],
        ]);

        $query = Appointment::query()->with('fieldOffice:id,name')
            ->when(! $user->seesAllOffices(), fn (Builder $q) => $q->where('field_office_id', $user->field_office_id))
            ->when($user->seesAllOffices() && ! empty($filters['office']), fn (Builder $q) => $q->where('field_office_id', $filters['office']))
            ->search($filters['search'] ?? null)
            ->when(! empty($filters['sg']), fn (Builder $q) => $q->where('salary_grade', $filters['sg']))
            ->when(! empty($filters['agency']), fn (Builder $q) => $q->where('agency', 'like', '%'.$filters['agency'].'%'))
            ->when(! empty($filters['date_from']), fn (Builder $q) => $q->whereDate('date_received', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn (Builder $q) => $q->whereDate('date_received', '<=', $filters['date_to']))
            ->when(! empty($filters['processor']), fn (Builder $q) => $q->where('processed_by', $filters['processor']))
            ->when(($filters['status'] ?? null) === 'pending', fn (Builder $q) => $q->unacted())
            ->when(($filters['status'] ?? null) === 'acted', fn (Builder $q) => $q->acted());

        $sort = self::SORTS[$filters['sort'] ?? 'date_received'];
        $dir = $filters['dir'] ?? 'desc';
        $query->orderBy($sort, $dir)->orderBy('id', 'desc');

        $appointments = $query->paginate(15)->withQueryString();

        return Inertia::render('Appointments/Index', [
            'appointments' => [
                'data' => $appointments->getCollection()->map(fn (Appointment $a) => $this->summary($a, $request))->values(),
                'current_page' => $appointments->currentPage(),
                'last_page' => $appointments->lastPage(),
                'total' => $appointments->total(),
                'prev_page_url' => $appointments->previousPageUrl(),
                'next_page_url' => $appointments->nextPageUrl(),
            ],
            'filters' => [
                'search' => $filters['search'] ?? '',
                'sg' => $filters['sg'] ?? '',
                'agency' => $filters['agency'] ?? '',
                'date_from' => $filters['date_from'] ?? '',
                'date_to' => $filters['date_to'] ?? '',
                'processor' => $filters['processor'] ?? '',
                'status' => $filters['status'] ?? '',
                'office' => $filters['office'] ?? '',
                'sort' => $filters['sort'] ?? 'date_received',
                'dir' => $dir,
            ],
            'agencies' => $this->lookups->agenciesFor($user->seesAllOffices() ? ($filters['office'] ?? null) : $user->field_office_id),
            'offices' => $user->seesAllOffices() ? $this->lookups->offices() : [],
        ]);
    }

    /**
     * Everything about one appointment, laid out for the read-only "View"
     * dialog on the list. JSON, fetched when the dialog opens, so the list
     * itself stays light.
     */
    public function details(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorize('view', $appointment);

        $a = $appointment->loadMissing('fieldOffice');
        $office = $a->fieldOffice;

        $date = fn (?CarbonInterface $d) => $d?->format('M j, Y') ?? '—';
        $text = fn (mixed $v) => filled($v) && $v !== 'N/A' ? (string) $v : '—';
        $yes = fn (bool $v) => $v ? 'Yes' : 'No';
        $time = $a->time_received ? date('g:i A', strtotime($a->time_received)) : '—';
        $publication = match (true) {
            $a->publication_start && $a->publication_end => $a->publication_start->format('M j, Y').' to '.$a->publication_end->format('M j, Y'),
            default => $date($a->publication_start ?? $a->publication_end),
        };

        $sections = [
            ['title' => 'Receipt', 'items' => [
                ['Field Office', $office?->name ?? '—'],
                ['Agency / Office', $a->agency],
                ['Sector', $text($a->sector)],
                ['Date received', $date($a->date_received)],
                ['Time received', $time],
                ['Received by', $text($a->received_by)],
                ['Date of issuance', $date($a->date_issued)],
                ['Date of effectivity / assumption', $date($a->date_effective)],
            ]],
            ['title' => 'Appointee', 'items' => [
                ['Name', $a->full_name],
                ['Sex', $a->sex === 'M' ? 'Male' : 'Female'],
                ['Person with disability', $yes($a->is_pwd)],
                ['Member of an indigenous group', $yes($a->is_indigenous)],
                ['Solo parent', $yes($a->is_solo_parent)],
                ['K-12 graduate', $yes($a->is_k12_graduate)],
            ]],
            ['title' => 'Position and appointment', 'items' => [
                ['Position title', $a->position],
                ['Salary grade', 'SG '.$a->salary_grade],
                ['Level', $text($a->level)],
                ['Employment status', $a->employment_status],
                ['Nature of appointment', $a->nature_of_appointment],
                ['Publication', $publication],
                ['Mode of publication', $text($a->publication_mode)],
            ]],
            ['title' => 'Eligibility', 'items' => [
                ['Type of eligibility', $text($a->eligibility_type)],
                ...($a->skoe_position ? [['SK position', $a->skoe_position]] : []),
                ['First-time entrant', $yes($a->is_first_time_entrant)],
                ['Date of exam', $text($a->eligibility_exam_date)],
                ['Place of exam', $text($a->eligibility_exam_place)],
                ['Date of issuance', $date($a->eligibility_issued_date)],
                ['Place of issuance', $text($a->eligibility_issued_place)],
                ['Validity of license', $date($a->eligibility_validity)],
            ]],
            ['title' => 'CSC action', 'items' => [
                ['Action', $a->csc_action ?? 'Awaiting action'],
                ['Action officer', $text($a->action_officer)],
                ['Date of action', $date($a->action_date)],
                ['Date of release', $date($a->release_date)],
                ['Reason, if pending', $text($a->pending_reason)],
                ['Reason, if disapproved or invalidated', $text($a->disapproval_reason)],
            ]],
        ];

        return response()->json([
            'id' => $a->id,
            'full_name' => $a->full_name,
            'subtitle' => "{$a->position} · SG {$a->salary_grade}",
            'processed_by' => $a->processed_by->value,
            'processed_by_label' => $a->processed_by_name,
            'csc_action' => $a->csc_action,
            'is_first_time_entrant' => $a->is_first_time_entrant,
            // Why it is processed there: the flow chart's rule for this office.
            'routing_note' => $office
                ? "{$office->name} ({$office->head_type->label()}): appointments of SG {$office->head_type->psedThreshold()} and above are processed by PSED, the rest by the Field Office."
                : null,
            'sections' => collect($sections)->map(fn ($s) => [
                'title' => $s['title'],
                'items' => collect($s['items'])->map(fn ($i) => ['label' => $i[0], 'value' => $i[1]])->all(),
            ])->all(),
            'can_update' => $request->user()->can('update', $a),
            // PSED administrators can open the record's history in the audit trail.
            'history_url' => $request->user()->can('view-audit-trail') ? "/audit-trail?subject=appointment:{$a->id}" : null,
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Appointment::class);

        $user = $request->user();

        return Inertia::render('Appointments/Create', [
            'options' => $this->lookups->formOptions(),
            'actionOfficers' => $this->actionOfficers($request->user()),
            'offices' => $this->lookups->offices(),
            'defaults' => [
                'field_office_id' => $user->field_office_id,
                // The person encoding it, when IMIS lists them among the Field Office employees.
                'received_by_imis_id' => $user->imis_user_id,
                'date_received' => now()->toDateString(),
            ],
        ]);
    }

    public function store(AppointmentRequest $request): RedirectResponse
    {
        $this->authorize('create', Appointment::class);

        $office = $this->officeFor($request);
        $data = $this->payload($request, $office);

        $appointment = Appointment::create($data + ['field_office_id' => $office->id]);

        AuditLog::record('appointment.create', $appointment, after: Arr::except($appointment->toArray(), ['created_at', 'updated_at']));

        return redirect()->route('appointments.index')
            ->with('status', "{$appointment->full_name} was encoded and routed to {$appointment->processed_by_name}.");
    }

    public function edit(Request $request, Appointment $appointment): Response
    {
        $this->authorize('update', $appointment);

        return Inertia::render('Appointments/Edit', [
            'appointment' => $this->detail($appointment),
            'options' => $this->lookups->formOptions(),
            'actionOfficers' => $this->actionOfficers($request->user()),
            'offices' => $this->lookups->offices(),
            'can_record_action' => $request->user()->can('record-action', [$appointment->fieldOffice, $appointment->processed_by]),
        ]);
    }

    public function update(AppointmentRequest $request, Appointment $appointment): RedirectResponse
    {
        $this->authorize('update', $appointment);

        // A Field Office cannot move an appointment to another office.
        $office = $request->user()->seesAllOffices() ? $this->officeFor($request) : $appointment->fieldOffice;
        $before = Arr::except($appointment->toArray(), ['updated_at']);

        // Action fields keep their stored values unless this user may record the action.
        $data = $this->payload($request, $office, $appointment);

        $appointment->update($data + ['field_office_id' => $office->id]);
        $appointment->refresh();

        AuditLog::record('appointment.update', $appointment, before: $before, after: Arr::except($appointment->toArray(), ['updated_at']));

        return redirect()->route('appointments.index')->with('status', "{$appointment->full_name} was updated.");
    }

    public function destroy(Appointment $appointment): RedirectResponse
    {
        $this->authorize('delete', $appointment);

        AuditLog::record('appointment.delete', $appointment, before: $appointment->toArray());
        $name = $appointment->full_name;
        $appointment->delete();

        return redirect()->route('appointments.index')->with('status', "{$name} was deleted.");
    }

    // ------------------------------------------------------------------ helpers

    private function officeFor(AppointmentRequest $request): FieldOffice
    {
        $user = $request->user();

        return $user->seesAllOffices()
            ? FieldOffice::findOrFail($request->integer('field_office_id'))
            : $user->fieldOffice;
    }

    /**
     * The validated attributes, with the CSC-action columns dropped (or kept
     * as stored) when the user is not the one the flow chart routes it to.
     *
     * @return array<string, mixed>
     */
    private function payload(AppointmentRequest $request, FieldOffice $office, ?Appointment $existing = null): array
    {
        // A typed action officer is never accepted here: only an IMIS employee's id (see withActionOfficer).
        $data = Arr::except($request->validated(), ['field_office_id', 'action_officer', 'received_by']);

        $processor = $this->router->route($office, (int) $data['salary_grade']);

        if (! $request->user()->can('record-action', [$office, $processor])) {
            $data = Arr::except($data, AppointmentRequest::ACTION_FIELDS);
        }

        $data = $this->withReceivedBy($data, $existing, $request->user());

        return $this->withActionOfficer($data, $existing, $request->user(), $processor);
    }

    /**
     * Who received the appointment is an IMIS employee of a Field Office, chosen from the same list as the
     * action officer but without the Office of the Regional Director. Only the employee's id comes from the
     * browser; the name stored is IMIS's. "keep" (or the same employee as before) leaves what is saved alone.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function withReceivedBy(array $data, ?Appointment $existing, User $user): array
    {
        if (! array_key_exists('received_by_imis_id', $data)) {
            return $data;
        }

        $choice = $data['received_by_imis_id'];
        unset($data['received_by_imis_id']);

        if ($choice === 'keep' || ($existing && $choice !== null && (string) $choice === (string) $existing->received_by_imis_id)) {
            return $data;
        }

        if ($choice === null || $choice === '') {
            return $data + ['received_by' => null, 'received_by_imis_id' => null];
        }

        try {
            $person = $this->directory->findInFieldOffice((int) $choice, $this->officeScope($user));
        } catch (DirectoryUnavailable $e) {
            throw ValidationException::withMessages(['received_by_imis_id' => $e->getMessage()]);
        }

        if ($person === null) {
            throw ValidationException::withMessages(['received_by_imis_id' => $user->seesAllOffices()
                ? 'Choose an employee of a Field Office from the list.'
                : 'Choose an employee of your office from the list.']);
        }

        return $data + ['received_by' => $person['name'], 'received_by_imis_id' => $person['id']];
    }

    /**
     * The action officer is an IMIS employee, chosen from the list, never typed. The browser sends
     * only which employee; the name stored (and printed on the MOA) is IMIS's, looked up here.
     * "keep" (or the same employee as before) leaves what is saved alone, so an officer who has
     * since left IMIS does not block an unrelated edit.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function withActionOfficer(array $data, ?Appointment $existing, User $user, Processor $processor): array
    {
        if (! array_key_exists('action_officer_imis_id', $data)) {
            return $data; // not sent, or this user may not record the action
        }

        $choice = $data['action_officer_imis_id'];
        unset($data['action_officer_imis_id'], $data['action_officer']);

        if ($choice === 'keep' || ($existing && $choice !== null && (string) $choice === (string) $existing->action_officer_imis_id)) {
            return $data;
        }

        if ($choice === null || $choice === '') {
            return $data + ['action_officer' => null, 'action_officer_imis_id' => null];
        }

        // Who may act depends on who processes the appointment (flow chart, step 4): the Director IV and
        // Director III of the Office of the Regional Director for what PSED processes, and the Field
        // Office's own people for what the Field Office processes (every Field Office's for PSED
        // administrators, only their own for a Field Office user).
        try {
            $person = $processor === Processor::Psed
                ? $this->directory->findDirector((int) $choice)
                : $this->directory->findInFieldOffice((int) $choice, $this->officeScope($user));
        } catch (DirectoryUnavailable $e) {
            throw ValidationException::withMessages(['action_officer_imis_id' => $e->getMessage()]);
        }

        if ($person === null) {
            throw ValidationException::withMessages(['action_officer_imis_id' => match (true) {
                $processor === Processor::Psed => 'Choose the Director IV or Director III from the list.',
                $user->seesAllOffices() => 'Choose an employee of a Field Office from the list.',
                default => 'Choose an employee of your office from the list.',
            }]);
        }

        return $data + ['action_officer' => $person['name'], 'action_officer_imis_id' => $person['id']];
    }

    /**
     * The people to choose an action officer from, highest salary grade first, or why they cannot be listed:
     *
     * - `groups`: for what the Field Office processes, the employees of the Field Offices, grouped by office
     *   (every Field Office's for PSED administrators, only their own office's for a Field Office user);
     * - `directors`: for what PSED processes, the Director IV and Director III of the Office of the Regional
     *   Director, and no one else of that office.
     *
     * @return array{groups: list<array<string, mixed>>, directors: list<array<string, mixed>>, error: ?string}
     */
    private function actionOfficers(User $user): array
    {
        $option = fn (array $e) => ['value' => (string) $e['id'], 'label' => ImisDirectory::label($e)];

        try {
            $roster = $this->directory->fieldOfficeRoster($this->officeScope($user));
            $directors = $this->directory->directors();
        } catch (DirectoryUnavailable $e) {
            return ['groups' => [], 'directors' => [], 'error' => $e->getMessage()];
        }

        $regional = $directors->first()['office_name'] ?? 'Office of the Regional Director';

        return [
            // One group per office, alphabetically; in each, the people who belong to it, highest grade first.
            // A Director II who holds a second office concurrently is listed in that office as well as their own.
            'groups' => collect($roster)
                ->sortBy(fn (array $office) => $office['name'])
                ->map(fn (array $office) => [
                    'label' => $office['name'],
                    'options' => ImisDirectory::byGrade($office['people'])->map($option)->all(),
                ])->values()->all(),
            'directors' => $directors->isEmpty() ? [] : [[
                'label' => $regional,
                'options' => ImisDirectory::byGrade($directors)->map($option)->all(),
            ]],
            'error' => null,
        ];
    }
    /** The IMIS office code a user's people lists are limited to: their own office, or null (all Field Offices) for PSED administrators. */
    private function officeScope(User $user): ?string
    {
        return $user->seesAllOffices() ? null : $user->fieldOffice?->imis_code;
    }

    /** @return array<string, mixed> */
    private function summary(Appointment $a, Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $a->id,
            'full_name' => $a->full_name,
            'sex' => $a->sex,
            'agency' => $a->agency,
            'position' => $a->position,
            'salary_grade' => $a->salary_grade,
            'employment_status' => $a->employment_status,
            'nature_of_appointment' => $a->nature_of_appointment,
            'date_received' => $a->date_received?->toDateString(),
            'is_first_time_entrant' => $a->is_first_time_entrant,
            'csc_action' => $a->csc_action,
            'processed_by' => $a->processed_by->value,
            'processed_by_label' => $a->processed_by_name,
            'office' => $a->fieldOffice?->name,
            'can_update' => $user->can('update', $a),
            'can_delete' => $user->can('delete', $a),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(Appointment $a): array
    {
        $dates = ['date_received', 'date_issued', 'date_effective', 'publication_start', 'publication_end',
            'eligibility_issued_date', 'eligibility_validity', 'action_date', 'release_date'];

        $data = Arr::except($a->attributesToArray(), [...$dates, 'processed_by', 'created_at', 'updated_at']);

        foreach ($dates as $field) {
            $data[$field] = $a->{$field}?->toDateString();
        }

        $data['time_received'] = $a->time_received ? substr($a->time_received, 0, 5) : null;
        $data['processed_by'] = $a->processed_by->value;
        $data['processed_by_label'] = $a->processed_by_name;

        return $data;
    }
}
