<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\FieldOffice;
use App\Models\ReferenceDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who did what, and when: a read-only history for PSED administrators. Rows
 * are only ever added (AuditLog), so this is also how a PSED override of a
 * Field Office's work is reviewed, with what each field was before and after.
 */
class AuditTrailController extends Controller
{
    /** The filter groups: key => [label, action prefix, or the exact actions]. */
    private const GROUPS = [
        'appointment' => ['Appointments', 'appointment.'],
        'reference' => ['Qualification Standards', 'reference.'],
        'field_office' => ['Field Offices', 'field_office.'],
        'agency' => ['Agencies', 'agency.'],
        'position' => ['Positions', 'position.'],
        'deadline' => ['Report deadlines', 'deadline.'],
        'report' => ['Reports', 'report.'],
        'auth' => ['Sign-ins and sign-outs', ['login', 'logout', 'session.limit_reached']],
    ];

    private const ACTIONS = [
        'login' => ['Signed in', 'neutral'],
        'logout' => ['Signed out', 'neutral'],
        'session.limit_reached' => ['Session ended (time limit)', 'warning'],
        'appointment.create' => ['Encoded an appointment', 'success'],
        'appointment.update' => ['Changed an appointment', 'info'],
        'appointment.delete' => ['Deleted an appointment', 'danger'],
        'appointment.import' => ['Imported appointments', 'success'],
        'reference.create' => ['Added a Qualification Standards document', 'success'],
        'reference.update' => ['Changed a Qualification Standards document', 'info'],
        'reference.delete' => ['Removed a Qualification Standards document', 'danger'],
        'field_office.update' => ['Changed a Field Office', 'info'],
        'position.create' => ['Added a position', 'success'],
        'position.update' => ['Renamed a position', 'info'],
        'position.delete' => ['Removed a position', 'danger'],
        'agency.rename' => ['Renamed an agency', 'info'],
        'agency.sync' => ['Synced the agency list from PSED', 'info'],
        'report.submit' => ['Submitted a report to PSED', 'info'],
        'report.accept' => ['Accepted a submitted report', 'success'],
        'report.return' => ['Returned a submitted report', 'warning'],
        'report.download_all' => ['Downloaded the submitted reports of a month', 'neutral'],
        'deadline.update' => ['Changed the usual report deadlines', 'info'],
        'deadline.set' => ['Set a month\'s report deadline', 'info'],
        'deadline.remove' => ['Removed a month\'s report deadline', 'neutral'],
        'report.generate' => ['Generated a report', 'neutral'],
    ];

    /** What a "subject" filter such as "appointment:12" points at. */
    private const SUBJECTS = [
        'appointment' => Appointment::class,
        'reference' => ReferenceDocument::class,
        'field_office' => FieldOffice::class,
    ];

    /** Columns that say nothing worth showing in a change list. */
    private const NOISE = ['id', 'created_at', 'updated_at'];

    public function index(Request $request): Response
    {
        $this->authorize('view-audit-trail');

        $filters = $request->validate([
            'group' => ['nullable', 'in:'.implode(',', array_keys(self::GROUPS))],
            'search' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'subject' => ['nullable', 'regex:/^('.implode('|', array_keys(self::SUBJECTS)).'):\d+$/'],
        ]);

        $logs = AuditLog::query()->with(['user:id,name,username', 'subject'])
            ->when(! empty($filters['group']), function (Builder $q) use ($filters) {
                [, $match] = self::GROUPS[$filters['group']];
                is_array($match) ? $q->whereIn('action', $match) : $q->where('action', 'like', $match.'%');
            })
            ->when(! empty($filters['search']), fn (Builder $q) => $q->whereHas('user', function (Builder $u) use ($filters) {
                $u->where('name', 'like', '%'.$filters['search'].'%')->orWhere('username', 'like', '%'.$filters['search'].'%');
            }))
            ->when(! empty($filters['date_from']), fn (Builder $q) => $q->where('created_at', '>=', $filters['date_from'].' 00:00:00'))
            ->when(! empty($filters['date_to']), fn (Builder $q) => $q->where('created_at', '<=', $filters['date_to'].' 23:59:59'))
            ->when(! empty($filters['subject']), function (Builder $q) use ($filters) {
                [$kind, $id] = explode(':', $filters['subject']);
                $q->where('subject_type', (new (self::SUBJECTS[$kind]))->getMorphClass())->where('subject_id', (int) $id);
            })
            ->latest('id')
            ->paginate(25)->withQueryString();

        return Inertia::render('AuditTrail/Index', [
            'entries' => [
                'data' => $logs->getCollection()->map(fn (AuditLog $log) => $this->present($log))->values(),
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'total' => $logs->total(),
                'prev_page_url' => $logs->previousPageUrl(),
                'next_page_url' => $logs->nextPageUrl(),
            ],
            'filters' => [
                'group' => $filters['group'] ?? '',
                'search' => $filters['search'] ?? '',
                'date_from' => $filters['date_from'] ?? '',
                'date_to' => $filters['date_to'] ?? '',
                'subject' => $filters['subject'] ?? '',
            ],
            'groups' => collect(self::GROUPS)->map(fn ($g, $key) => ['value' => $key, 'label' => $g[0]])->values()->all(),
        ]);
    }

    /** @return array<string, mixed> */
    private function present(AuditLog $log): array
    {
        [$label, $tone] = self::ACTIONS[$log->action] ?? [$log->action, 'neutral'];

        return [
            'id' => $log->id,
            'at' => $log->created_at?->toIso8601String(),
            'user' => $log->user?->name,
            'username' => $log->user?->username,
            'action' => $log->action,
            'action_label' => $label,
            'tone' => $tone,
            'subject' => $this->subjectLabel($log),
            'subject_filter' => $this->subjectFilter($log),
            'ip' => $log->ip,
            'changes' => $this->changes($log),
        ];
    }

    /** "Appointment: Reyes, Ana", "Qualification Standards: <title>", "MOA · October 2026 · ESFO". */
    private function subjectLabel(AuditLog $log): ?string
    {
        $data = $log->after ?? $log->before ?? [];

        if ($log->action === 'appointment.import') {
            return ($data['file'] ?? 'a file').' · '.($data['imported'] ?? 0).' imported';
        }

        if ($log->action === 'report.generate') {
            $month = isset($data['month']) ? date('F', mktime(0, 0, 0, (int) $data['month'], 1)) : '';

            return trim(strtoupper((string) ($data['type'] ?? 'Report')).' · '.$month.' '.($data['year'] ?? '').' · '.($data['office'] ?? ''), ' ·');
        }

        if (str_starts_with($log->action, 'position.') || $log->action === 'agency.rename') {
            $kind = str_starts_with($log->action, 'position.') ? 'Position' : 'Agency';
            $was = $log->before['name'] ?? null;
            $now = $log->after['name'] ?? null;

            return "{$kind}: ".($was && $now ? "{$was} → {$now}" : ($now ?? $was ?? '—'));
        }

        $subject = $log->subject;
        $before = $log->before ?? [];
        $after = $log->after ?? [];

        return match (true) {
            $log->subject_type === (new Appointment)->getMorphClass() => 'Appointment: '.($subject?->full_name
                ?? trim(($after['last_name'] ?? $before['last_name'] ?? '?').', '.($after['first_name'] ?? $before['first_name'] ?? ''))),
            $log->subject_type === (new ReferenceDocument)->getMorphClass() => 'Document: '.($subject?->title ?? $after['title'] ?? $before['title'] ?? '#'.$log->subject_id),
            $log->subject_type === (new FieldOffice)->getMorphClass() => 'Field Office: '.($subject?->name ?? '#'.$log->subject_id),
            default => null,
        };
    }

    /** The "History" filter for this row's subject, when it has one worth filtering by. */
    private function subjectFilter(AuditLog $log): ?string
    {
        foreach (self::SUBJECTS as $kind => $class) {
            if ($log->subject_type === (new $class)->getMorphClass() && $log->subject_id) {
                return "{$kind}:{$log->subject_id}";
            }
        }

        return null;
    }

    /**
     * What changed, field by field. An update lists only the fields whose value
     * differs; a create lists what was entered; a delete lists what was removed.
     *
     * @return list<array{field: string, from: ?string, to: ?string}>
     */
    private function changes(AuditLog $log): array
    {
        $before = collect($log->before ?? [])->except(self::NOISE);
        $after = collect($log->after ?? [])->except(self::NOISE);

        if ($before->isNotEmpty() && $after->isNotEmpty()) {
            $keys = $before->keys()->merge($after->keys())->unique();

            return $keys
                ->filter(fn ($k) => $this->show($before[$k] ?? null) !== $this->show($after[$k] ?? null))
                ->map(fn ($k) => ['field' => Str::headline($k), 'from' => $this->show($before[$k] ?? null), 'to' => $this->show($after[$k] ?? null)])
                ->values()->all();
        }

        // A create, a delete, or something with only one side.
        $side = $after->isNotEmpty() ? $after : $before;
        $isDelete = $after->isEmpty();

        return $side->filter(fn ($v) => $v !== null && $v !== '' && $v !== [])
            ->map(fn ($v, $k) => ['field' => Str::headline($k), 'from' => $isDelete ? $this->show($v) : null, 'to' => $isDelete ? null : $this->show($v)])
            ->values()->all();
    }

    /** A value as people read it: dates without the midnight, Yes/No, no raw JSON noise. */
    private function show(mixed $value): ?string
    {
        return match (true) {
            $value === null || $value === '' => null,
            is_bool($value) => $value ? 'Yes' : 'No',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
            is_string($value) && preg_match('/^(\d{4}-\d{2}-\d{2})T00:00:00/', $value, $m) === 1 => $m[1],
            default => (string) $value,
        };
    }
}
