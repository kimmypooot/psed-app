<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\FieldOffice;
use App\Services\LookupService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Appointment analytics: what was received, how it was decided and how long that took, broken down by
 * the details of the appointment (status, nature, level, sector, salary grade, agency, position,
 * eligibility, office, who processed it) and by month. Counted by the date the appointment was
 * received. For PSED administrators, across every Field Office or one.
 *
 * Everything is tallied in one pass over a lean read of the appointments and cached until any
 * appointment changes (Appointment::statsVersion).
 */
class AppointmentAnalyticsController extends Controller
{
    private const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    private const GRADE_BANDS = [[1, 10, 'SG 1–10'], [11, 15, 'SG 11–15'], [16, 20, 'SG 16–20'], [21, 24, 'SG 21–24'], [25, 30, 'SG 25–30'], [31, 33, 'SG 31–33']];

    private const TURNAROUND_BANDS = [[0, 3, '0–3 days'], [4, 7, '4–7 days'], [8, 15, '8–15 days'], [16, 30, '16–30 days'], [31, PHP_INT_MAX, 'Over 30 days']];

    private const AGING_BANDS = [[0, 14, 'Under 15 days'], [15, 30, '15–30 days'], [31, 60, '31–60 days'], [61, PHP_INT_MAX, 'Over 60 days']];

    public function __construct(private readonly LookupService $lookups) {}

    public function index(Request $request): Response
    {
        $this->authorize('view-statistics');

        $data = $request->validate([
            'year' => ['nullable', 'regex:/^(all|\d{4})$/'],
            'field_office_id' => ['nullable', 'integer', 'exists:field_offices,id'],
            'type' => ['nullable', 'in:plantilla,casual-contractual'],
        ]);

        $filters = [
            'year' => $data['year'] ?? (string) now()->year,
            'field_office_id' => $data['field_office_id'] ?? '',
            'type' => $data['type'] ?? '',
        ];

        $stats = Cache::remember(
            'appointment-analytics.'.Appointment::statsVersion().'.'.md5(json_encode($filters).now()->toDateString()),
            now()->addMinutes(10),
            fn () => $this->compute($filters),
        );

        return Inertia::render('Statistics/AppointmentAnalytics', [
            'filters' => $filters,
            'stats' => $stats,
            'years' => $this->years(),
            'offices' => $this->lookups->offices(),
        ]);
    }

    /** @param  array<string, mixed>  $filters */
    private function compute(array $filters): array
    {
        $casual = config('psed.casual_contractual_statuses');
        $approved = config('psed.approved_actions');
        $rejected = config('psed.rejected_actions');
        $today = CarbonImmutable::today();
        $offices = FieldOffice::pluck('name', 'id');

        $query = Appointment::query()->toBase()
            ->when($filters['field_office_id'] !== '', fn ($q) => $q->where('field_office_id', $filters['field_office_id']))
            ->when($filters['year'] !== 'all', fn ($q) => $q->whereBetween('date_received', [$filters['year'].'-01-01', $filters['year'].'-12-31']))
            ->when($filters['type'] === 'plantilla', fn ($q) => $q->whereNotIn('employment_status', $casual))
            ->when($filters['type'] === 'casual-contractual', fn ($q) => $q->whereIn('employment_status', $casual))
            ->select(['field_office_id', 'agency', 'sector', 'date_received', 'position', 'salary_grade', 'level', 'employment_status',
                'nature_of_appointment', 'publication_mode', 'eligibility_type', 'is_first_time_entrant', 'is_pwd', 'is_indigenous',
                'is_solo_parent', 'is_k12_graduate', 'sex', 'csc_action', 'action_date', 'processed_by']);

        $total = 0;
        $outcome = ['approved' => 0, 'rejected' => 0, 'pending' => 0];
        $actions = [];
        $flags = ['pwd' => 0, 'indigenous' => 0, 'solo_parent' => 0, 'k12' => 0, 'first_time' => 0];
        $dims = array_fill_keys(['employment_status', 'nature', 'level', 'sector', 'sex', 'eligibility', 'publication', 'grade', 'processed_by', 'office', 'agency', 'position'], []);
        $months = [];
        $days = [];
        $byProcessor = ['PSED' => [], 'Field Office' => []];
        $aging = array_fill(0, count(self::AGING_BANDS), 0);

        foreach ($query->cursor() as $r) {
            $total++;
            $received = CarbonImmutable::parse($r->date_received);

            $group = in_array($r->csc_action, $approved, true) ? 'approved' : (in_array($r->csc_action, $rejected, true) ? 'rejected' : 'pending');
            $outcome[$group]++;
            $label = $r->csc_action ?: 'Pending';
            $actions[$label] = ($actions[$label] ?? 0) + 1;

            foreach ([['pwd', $r->is_pwd], ['indigenous', $r->is_indigenous], ['solo_parent', $r->is_solo_parent], ['k12', $r->is_k12_graduate], ['first_time', $r->is_first_time_entrant]] as [$key, $on]) {
                $flags[$key] += $on ? 1 : 0;
            }

            $grade = (int) $r->salary_grade;
            $band = collect(self::GRADE_BANDS)->first(fn ($b) => $grade >= $b[0] && $grade <= $b[1])[2] ?? 'Other';
            $processor = $r->processed_by === 'psed' ? 'PSED' : 'Field Office';

            $tally = [
                'employment_status' => $r->employment_status,
                'nature' => $r->nature_of_appointment,
                'level' => $r->level ? ucfirst((string) $r->level) : 'Not stated',
                'sector' => $r->sector ?: 'Not stated',
                'sex' => $r->sex === 'M' ? 'Male' : 'Female',
                'eligibility' => $r->eligibility_type ?: 'None / not stated',
                'publication' => $r->publication_mode ?: 'Not stated',
                'grade' => $band,
                'processed_by' => $processor,
                'office' => $offices[$r->field_office_id] ?? 'Unknown office',
                'agency' => $r->agency,
                'position' => $r->position,
            ];
            foreach ($tally as $dim => $value) {
                $dims[$dim][$value] ??= ['total' => 0, 'approved' => 0, 'rejected' => 0, 'pending' => 0];
                $dims[$dim][$value]['total']++;
                $dims[$dim][$value][$group]++;
            }

            // The trend: by month in a year, by year across all of them.
            $slot = $filters['year'] === 'all' ? $received->format('Y') : $received->format('n');
            $months[$slot] ??= ['total' => 0, 'approved' => 0, 'rejected' => 0, 'pending' => 0];
            $months[$slot]['total']++;
            $months[$slot][$group]++;

            // How long the decision took, for those decided; how long it has waited, for those still pending.
            if ($group !== 'pending' && $r->action_date) {
                $d = max(0, (int) $received->diffInDays(CarbonImmutable::parse($r->action_date)));
                $days[] = $d;
                $byProcessor[$processor][] = $d;
            } elseif ($group === 'pending') {
                $age = max(0, (int) $received->diffInDays($today));
                foreach (self::AGING_BANDS as $i => [$lo, $hi]) {
                    if ($age >= $lo && $age <= $hi) {
                        $aging[$i]++;
                        break;
                    }
                }
            }
        }

        $decided = $outcome['approved'] + $outcome['rejected'];

        return [
            'total' => $total,
            'outcome' => $outcome,
            'approval_rate' => $decided ? round($outcome['approved'] / $decided * 100, 1) : null,
            'decided' => $decided,
            'flags' => $flags,
            'actions' => $this->ranked($actions),
            'trend' => $this->trend($months, $filters['year']),
            'turnaround' => [
                'count' => count($days),
                'average' => $days ? round(array_sum($days) / count($days), 1) : null,
                'median' => $this->median($days),
                'slowest' => $days ? max($days) : null,
                'bands' => $this->bands($days, self::TURNAROUND_BANDS),
                'by_processor' => collect($byProcessor)->map(fn ($d, $name) => ['label' => $name, 'count' => count($d), 'average' => $d ? round(array_sum($d) / count($d), 1) : null])->values()->all(),
            ],
            'aging' => collect(self::AGING_BANDS)->map(fn ($b, $i) => ['label' => $b[2], 'count' => $aging[$i]])->all(),
            'breakdowns' => [
                'employment_status' => $this->breakdown($dims['employment_status']),
                'nature' => $this->breakdown($dims['nature']),
                'level' => $this->breakdown($dims['level']),
                'sex' => $this->breakdown($dims['sex']),
                'processed_by' => $this->breakdown($dims['processed_by']),
                'grade' => collect(self::GRADE_BANDS)->map(fn ($b) => ['label' => $b[2]] + ($dims['grade'][$b[2]] ?? ['total' => 0, 'approved' => 0, 'rejected' => 0, 'pending' => 0]))->all(),
                'sector' => $this->breakdown($dims['sector']),
                'eligibility' => $this->breakdown($dims['eligibility'], 8),
                'publication' => $this->breakdown($dims['publication']),
                'office' => $this->breakdown($dims['office'], 12),
                'agency' => $this->breakdown($dims['agency'], 10),
                'position' => $this->breakdown($dims['position'], 10),
            ],
            'office_count' => count($dims['office']),
            'agency_count' => count($dims['agency']),
            'position_count' => count($dims['position']),
            'generated_at' => now()->format('M j, Y g:i A'),
        ];
    }

    /** @param  array<string, array<string, int>>  $rows */
    private function breakdown(array $rows, ?int $limit = null): array
    {
        $list = collect($rows)->map(fn ($c, $label) => ['label' => (string) $label] + $c)->sortByDesc('total')->values();

        return ($limit ? $list->take($limit) : $list)->all();
    }

    /** @param  array<string, int>  $counts */
    private function ranked(array $counts): array
    {
        arsort($counts);

        return collect($counts)->map(fn ($n, $label) => ['label' => (string) $label, 'count' => $n])->values()->all();
    }

    /** @param  array<string, array<string, int>>  $months */
    private function trend(array $months, string $year): array
    {
        $blank = ['total' => 0, 'approved' => 0, 'rejected' => 0, 'pending' => 0];

        if ($year === 'all') {
            ksort($months);

            return collect($months)->map(fn ($c, $y) => ['label' => (string) $y] + $c)->values()->all();
        }

        return collect(self::MONTHS)->map(fn ($name, $i) => ['label' => $name] + ($months[$i + 1] ?? $blank))->all();
    }

    /**
     * @param  list<int>  $days
     * @param  list<array{0: int, 1: int, 2: string}>  $bands
     */
    private function bands(array $days, array $bands): array
    {
        return collect($bands)->map(fn ($b) => ['label' => $b[2], 'count' => count(array_filter($days, fn ($d) => $d >= $b[0] && $d <= $b[1]))])->all();
    }

    /** @param  list<int>  $days */
    private function median(array $days): ?float
    {
        if ($days === []) {
            return null;
        }

        sort($days);
        $mid = intdiv(count($days), 2);

        return (float) (count($days) % 2 ? $days[$mid] : ($days[$mid - 1] + $days[$mid]) / 2);
    }

    /** @return list<string> */
    private function years(): array
    {
        return array_map('strval', range(now()->year + 1, max(2020, now()->year - 10)));
    }
}
