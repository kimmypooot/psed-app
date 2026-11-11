<?php

namespace App\Http\Controllers;

use App\Enums\Processor;
use App\Models\Appointment;
use App\Models\FieldOffice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $today = CarbonImmutable::today();
        $start = $today->startOfMonth();
        $end = $today->endOfMonth()->startOfDay();
        $yearStart = $today->startOfYear();

        // Everything is limited to the user's own office unless they work across offices.
        $scope = fn (): Builder => Appointment::query()
            ->when(! $user->seesAllOffices(), fn (Builder $q) => $q->where('field_office_id', $user->field_office_id));

        $backlog = fn (Processor $who) => $scope()->where('processed_by', $who->value)->unacted()->count();

        // Receipts and actions per month of this year, for the trend bars.
        $received = $scope()->whereBetween('date_received', [$yearStart->toDateString(), $today->endOfYear()->toDateString()])->pluck('date_received');
        $acted = $scope()->acted()->whereBetween('action_date', [$yearStart->toDateString(), $today->endOfYear()->toDateString()])->pluck('action_date');

        $trend = collect(range(1, 12))->map(fn (int $m) => [
            'month' => CarbonImmutable::create($today->year, $m, 1)->format('M'),
            'received' => $received->filter(fn ($d) => $d->month === $m)->count(),
            'acted' => $acted->filter(fn ($d) => $d->month === $m)->count(),
        ])->all();

        $office = $user->fieldOffice;

        return Inertia::render('Dashboard', [
            'generatedAt' => now()->toIso8601String(),
            'period' => $today->format('F Y'),
            'stats' => [
                'received' => $scope()->receivedBetween($start, $end)->count(),
                'acted' => $scope()->actedBetween($start, $end)->count(),
                'backlog' => $scope()->unacted()->count(),
                'first_time' => $scope()->firstTimeApprovedBetween($start, $end)->count(),
                'field_office_queue' => $backlog(Processor::FieldOffice),
                'psed_queue' => $backlog(Processor::Psed),
            ],
            'trend' => $trend,
            'recent' => $scope()->with('fieldOffice:id,name')->latest('id')->limit(8)->get()->map(fn (Appointment $a) => [
                'id' => $a->id,
                'full_name' => $a->full_name,
                'agency' => $a->agency,
                'position' => $a->position,
                'salary_grade' => $a->salary_grade,
                'date_received' => $a->date_received?->toDateString(),
                'processed_by_label' => $a->processed_by_name,
                'processed_by' => $a->processed_by->value,
                'csc_action' => $a->csc_action,
                'office' => $a->fieldOffice?->name,
            ]),
            // The routing rule that applies to the user's own office.
            'rule' => $office ? [
                'office' => $office->name,
                'head_label' => $office->head_type->label(),
                'psed_from_sg' => $office->head_type->psedThreshold(),
            ] : null,
            // PSED monitoring: every Field Office at a glance.
            'offices' => $user->seesAllOffices() ? $this->officeRows($start, $end) : [],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function officeRows(CarbonImmutable $start, CarbonImmutable $end): array
    {
        return FieldOffice::orderBy('name')->get()->map(function (FieldOffice $o) use ($start, $end) {
            $q = fn (): Builder => Appointment::query()->where('field_office_id', $o->id);

            return [
                'id' => $o->id,
                'name' => $o->name,
                'head_label' => $o->head_type->label(),
                'psed_from_sg' => $o->head_type->psedThreshold(),
                'received' => $q()->receivedBetween($start, $end)->count(),
                'acted' => $q()->actedBetween($start, $end)->count(),
                'psed_backlog' => $q()->where('processed_by', Processor::Psed->value)->unacted()->count(),
                'fo_backlog' => $q()->where('processed_by', Processor::FieldOffice->value)->unacted()->count(),
            ];
        })->all();
    }
}
