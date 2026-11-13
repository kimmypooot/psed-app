<?php

namespace App\Http\Controllers;

use App\Enums\Processor;
use App\Models\Appointment;
use App\Models\FieldOffice;
use App\Models\OpeningBalance;
use App\Services\OpeningBalances;
use App\Services\Reports\MonthlyFigures;
use App\Services\Reports\ReportContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /** How long the figures are kept; any appointment change also invalidates them (Appointment::bumpStatsVersion). */
    private const TTL_SECONDS = 120;

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $today = CarbonImmutable::today();
        $start = $today->startOfMonth();
        $end = $today->endOfMonth()->startOfDay();

        // Everything is limited to the user's own office unless they work across offices.
        $officeId = $user->seesAllOffices() ? null : $user->field_office_id;

        // Heavy aggregates are computed once per office scope and shared by everyone looking at it.
        $key = sprintf('dashboard:v%d:%s:%s', Appointment::statsVersion(), $officeId ?? 'all', $today->toDateString());

        $figures = Cache::remember($key, self::TTL_SECONDS, fn () => [
            'stats' => $this->stats($officeId, $start, $end),
            'trend' => $this->trend($officeId, $today),
            'offices' => $user->seesAllOffices() ? $this->officeRows($start, $end) : [],
            // A Field Office is shown what PSED set (or what the appointments show) as its balance carried in.
            'balance' => $officeId ? $this->balance($officeId, $today) : null,
        ]);

        $office = $user->fieldOffice;

        return Inertia::render('Dashboard', [
            'generatedAt' => now()->toIso8601String(),
            'period' => $today->format('F Y'),
            'stats' => $figures['stats'],
            'trend' => $figures['trend'],
            // For the cards that lead to the list: this month, as the list's own date filter takes it.
            'month' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            // What has waited longest for action: the next thing to pick up. Always fresh, five rows.
            'waiting' => Appointment::query()->unacted()
                ->when($officeId, fn (Builder $q) => $q->where('field_office_id', $officeId))
                ->with('fieldOffice:id,name')->orderBy('date_received')->orderBy('id')->limit(5)->get()
                ->map(fn (Appointment $a) => [
                    'id' => $a->id,
                    'full_name' => $a->full_name,
                    'agency' => $a->agency,
                    'position' => $a->position,
                    'salary_grade' => $a->salary_grade,
                    'date_received' => $a->date_received?->toDateString(),
                    'days' => (int) $a->date_received?->diffInDays($today),
                    'processed_by_label' => $a->processed_by_name,
                    'processed_by' => $a->processed_by->value,
                    'office' => $a->fieldOffice?->name,
                ]),
            // Always fresh: eight rows by primary key.
            'recent' => Appointment::query()
                ->when($officeId, fn (Builder $q) => $q->where('field_office_id', $officeId))
                ->with('fieldOffice:id,name')->latest('id')->limit(8)->get()->map(fn (Appointment $a) => [
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
            'offices' => $figures['offices'],
            'balance' => $figures['balance'],
        ]);
    }

    /**
     * The conditional-aggregate expressions every figure is built from: one pass over
     * the rows instead of one query per number.
     *
     * @return array{0: string, 1: list<mixed>} the SELECT list and its bindings, in order
     */
    private function aggregates(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $finals = config('psed.final_actions');
        $approved = config('psed.approved_actions');
        $marks = fn (array $list) => implode(',', array_fill(0, count($list), '?'));
        $unacted = "(action_date IS NULL OR csc_action IS NULL OR csc_action = 'Pending')";
        [$from, $to] = [$start->toDateString(), $end->toDateString()];

        $sql = implode(', ', [
            'COALESCE(SUM(date_received BETWEEN ? AND ?), 0) AS received',
            "COALESCE(SUM(action_date IS NOT NULL AND csc_action IN ({$marks($finals)}) AND action_date BETWEEN ? AND ?), 0) AS acted",
            "COALESCE(SUM({$unacted}), 0) AS backlog",
            "COALESCE(SUM(is_first_time_entrant = 1 AND csc_action IN ({$marks($approved)}) AND action_date BETWEEN ? AND ?), 0) AS first_time",
            "COALESCE(SUM({$unacted} AND processed_by = ?), 0) AS field_office_queue",
            "COALESCE(SUM({$unacted} AND processed_by = ?), 0) AS psed_queue",
        ]);

        return [$sql, [
            $from, $to,
            ...$finals, $from, $to,
            ...$approved, $from, $to,
            Processor::FieldOffice->value,
            Processor::Psed->value,
        ]];
    }

    /**
     * The appointments an office carried into this year (unacted on 31 December), how many of them have been
     * acted on since, and who said so. Nothing to show when there was no balance.
     *
     * @return array{year: int, value: int, remaining: int, acted: int, source: string, note: ?string, set_by: ?string, set_at: ?string}|null
     */
    private function balance(int $officeId, CarbonImmutable $today): ?array
    {
        $office = FieldOffice::find($officeId);
        if ($office === null) {
            return null;
        }

        $balance = app(OpeningBalances::class)->for($office, $today->year);
        $set = OpeningBalance::with('updater:id,name')->where('field_office_id', $officeId)->where('year', $today->year)->first();

        if ($balance['value'] === 0 && $set === null) {
            return null;
        }

        // What is still unacted of it at the end of this month, by the same rule as the MRAP's section G.
        $left = MonthlyFigures::compute(new ReportContext($office, $today->year, $today->month), [$today->month])[$today->month]['backlog_end'];

        return [
            'year' => $today->year,
            'value' => $balance['value'],
            'remaining' => $left,
            'acted' => max(0, $balance['value'] - $left),
            'source' => $balance['source'],
            'note' => $set?->note,
            'set_by' => $set?->updater?->name,
            'set_at' => $set?->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, int> */
    private function stats(?int $officeId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        [$sql, $bindings] = $this->aggregates($start, $end);

        $row = Appointment::query()
            ->when($officeId, fn (Builder $q) => $q->where('field_office_id', $officeId))
            ->selectRaw($sql, $bindings)
            ->toBase()->first();

        return array_map('intval', (array) $row);
    }

    /**
     * Receipts and actions per month of this year, for the trend bars: grouped in SQL
     * over an index range, not counted in PHP.
     *
     * @return list<array{month: string, received: int, acted: int}>
     */
    private function trend(?int $officeId, CarbonImmutable $today): array
    {
        $from = $today->startOfYear()->toDateString();
        $to = $today->endOfYear()->toDateString();
        $scope = fn (): Builder => Appointment::query()->when($officeId, fn (Builder $q) => $q->where('field_office_id', $officeId));
        $month = fn (string $column): string => DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(strftime('%m', {$column}) AS INTEGER)"
            : "MONTH({$column})";

        $received = $scope()->whereBetween('date_received', [$from, $to])
            ->selectRaw("{$month('date_received')} AS m, COUNT(*) AS n")->groupBy('m')->toBase()->pluck('n', 'm');
        $acted = $scope()->acted()->whereBetween('action_date', [$from, $to])
            ->selectRaw("{$month('action_date')} AS m, COUNT(*) AS n")->groupBy('m')->toBase()->pluck('n', 'm');

        return collect(range(1, 12))->map(fn (int $m) => [
            'month' => CarbonImmutable::create($today->year, $m, 1)->format('M'),
            'received' => (int) ($received[$m] ?? 0),
            'acted' => (int) ($acted[$m] ?? 0),
        ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function officeRows(CarbonImmutable $start, CarbonImmutable $end): array
    {
        [$sql, $bindings] = $this->aggregates($start, $end);

        // One grouped query for every office, not four counts per office.
        $figures = Appointment::query()
            ->selectRaw("field_office_id, {$sql}", $bindings)
            ->groupBy('field_office_id')
            ->toBase()->get()->keyBy('field_office_id');

        return FieldOffice::orderBy('name')->get()->map(function (FieldOffice $o) use ($figures) {
            $f = $figures[$o->id] ?? null;

            return [
                'id' => $o->id,
                'name' => $o->name,
                'head_label' => $o->head_type->label(),
                'psed_from_sg' => $o->head_type->psedThreshold(),
                'received' => (int) ($f->received ?? 0),
                'acted' => (int) ($f->acted ?? 0),
                'psed_backlog' => (int) ($f->psed_queue ?? 0),
                'fo_backlog' => (int) ($f->field_office_queue ?? 0),
            ];
        })->all();
    }
}
