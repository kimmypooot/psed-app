<?php

namespace App\Http\Controllers;

use App\Enums\ReportType;
use App\Models\AuditLog;
use App\Models\ReportDeadline;
use App\Services\ReportDeadlines;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * When the Field Offices' monthly reports are due: each report's usual day of the following month, and a date
 * of its own for a single month when the usual one does not hold (a holiday, an extension). PSED administrators only.
 */
class ReportDeadlineController extends Controller
{
    public function index(ReportDeadlines $deadlines): Response
    {
        $this->authorize('manage-offices');

        return Inertia::render('Settings/ReportDeadlines', [
            'types' => collect(ReportType::cases())->map(fn (ReportType $t) => [
                'value' => $t->value,
                'label' => $t->label(),
                'full_name' => $t->fullName(),
                'day' => $deadlines->day($t),
            ])->all(),
            'overrides' => $deadlines->overrides()->map(fn (ReportDeadline $d) => [
                'id' => $d->id,
                'type' => $d->type->value,
                'label' => $d->type->label(),
                'year' => $d->year,
                'month' => $d->month,
                'due_date' => $d->due_date->toDateString(),
            ])->values(),
            'years' => range(now()->year + 1, now()->year - 1),
        ]);
    }

    /** The usual day of the following month, for each report. */
    public function update(Request $request, ReportDeadlines $deadlines): RedirectResponse
    {
        $this->authorize('manage-offices');

        $rules = [];
        foreach (ReportType::cases() as $type) {
            $rules["days.{$type->value}"] = ['required', 'integer', 'between:1,28'];
        }

        $data = $request->validate($rules, [
            'days.*.required' => 'Enter a day for every report.',
            'days.*.between' => 'The day must be between 1 and 28.',
            'days.*.integer' => 'The day must be a number.',
        ]);

        $before = $deadlines->days();

        foreach (ReportType::cases() as $type) {
            ReportDeadline::updateOrCreate(
                ['type' => $type->value, 'year' => null, 'month' => null],
                ['due_day' => (int) $data['days'][$type->value], 'due_date' => null, 'updated_by' => $request->user()->id],
            );
        }

        $deadlines->forget();
        AuditLog::record('deadline.update', before: $before, after: $deadlines->days());

        return back()->with('status', 'The usual deadlines were saved.');
    }

    /** One month's own date for one report, or for all of them. */
    public function store(Request $request, ReportDeadlines $deadlines): RedirectResponse
    {
        $this->authorize('manage-offices');

        $data = $request->validate([
            'type' => ['required', Rule::in(['all', ...array_map(fn (ReportType $t) => $t->value, ReportType::cases())])],
            'year' => ['required', 'integer', 'between:2020,'.(now()->year + 1)],
            'month' => ['required', 'integer', 'between:1,12'],
            'due_date' => ['required', 'date'],
        ], ['due_date.required' => 'Choose the new deadline.']);

        $start = Carbon::create((int) $data['year'], (int) $data['month'], 1);

        if (Carbon::parse($data['due_date'])->lt($start->copy()->addMonthNoOverflow()->startOfMonth()->subDay())) {
            throw ValidationException::withMessages(['due_date' => 'The deadline cannot be before the month ends.']);
        }

        $types = $data['type'] === 'all' ? ReportType::cases() : [ReportType::from($data['type'])];

        foreach ($types as $type) {
            $before = $deadlines->dueDate($type, (int) $data['year'], (int) $data['month'])->toDateString();

            ReportDeadline::updateOrCreate(
                ['type' => $type->value, 'year' => (int) $data['year'], 'month' => (int) $data['month']],
                ['due_date' => $data['due_date'], 'due_day' => null, 'updated_by' => $request->user()->id],
            );

            AuditLog::record('deadline.set', before: ['due' => $before], after: [
                'type' => $type->value, 'year' => (int) $data['year'], 'month' => (int) $data['month'], 'due' => $data['due_date'],
            ]);
        }

        return back()->with('status', 'The deadline for '.date('F', mktime(0, 0, 0, (int) $data['month'], 1))." {$data['year']} was set.");
    }

    public function destroy(ReportDeadline $deadline): RedirectResponse
    {
        $this->authorize('manage-offices');
        abort_if($deadline->year === null, 404);

        AuditLog::record('deadline.remove', before: [
            'type' => $deadline->type->value, 'year' => $deadline->year, 'month' => $deadline->month, 'due' => $deadline->due_date->toDateString(),
        ]);
        $deadline->delete();

        return back()->with('status', 'That month goes back to the usual deadline.');
    }
}
