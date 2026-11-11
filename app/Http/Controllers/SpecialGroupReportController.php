<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\FieldOffice;
use App\Services\LookupService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * How many appointments were for a person with disability, a member of an indigenous group or a
 * solo parent, by month, quarter, semester or year. Counted by the date the appointment was
 * received. A person may belong to more than one group, so the groups do not add up to the total.
 */
class SpecialGroupReportController extends Controller
{
    private const PERIODS = ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'semestral' => 'Semestral', 'yearly' => 'Yearly'];

    public function __construct(private readonly LookupService $lookups) {}

    public function index(Request $request): Response
    {
        $this->authorize('view-special-group-report');
        [$filters, $rows] = $this->build($request);

        return Inertia::render('Statistics/SpecialGroups', [
            'filters' => $filters,
            'rows' => $rows,
            'totals' => $this->totals($rows),
            'periods' => collect(self::PERIODS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all(),
            'years' => $this->years(),
            'offices' => $this->lookups->offices(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorize('view-special-group-report');
        [$filters, $rows] = $this->build($request);
        $totals = $this->totals($rows);

        AuditLog::record('report.generate', null, after: ['type' => 'special-groups', 'period' => $filters['period'], 'year' => $filters['year'], 'office' => $filters['field_office_id'] ?: 'all']);

        $name = 'special-groups-'.$filters['period'].($filters['period'] === 'yearly' ? '' : '-'.$filters['year']).'.csv';

        return response()->streamDownload(function () use ($rows, $totals) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads it as UTF-8
            fputcsv($out, ['Period', 'Persons with Disability', 'Indigenous Group Members', 'Solo Parents', 'All Appointments']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['label'], $r['pwd'], $r['indigenous'], $r['solo_parent'], $r['total']]);
            }
            fputcsv($out, ['Total', $totals['pwd'], $totals['indigenous'], $totals['solo_parent'], $totals['total']]);
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0: array<string, mixed>, 1: list<array<string, mixed>>} */
    private function build(Request $request): array
    {
        $data = $request->validate([
            'period' => ['nullable', 'in:'.implode(',', array_keys(self::PERIODS))],
            'year' => ['nullable', 'integer', 'between:2000,'.(now()->year + 1)],
            'field_office_id' => ['nullable', 'integer', 'exists:field_offices,id'],
        ]);

        $filters = [
            'period' => $data['period'] ?? 'monthly',
            'year' => (int) ($data['year'] ?? now()->year),
            'field_office_id' => $data['field_office_id'] ?? '',
        ];

        $query = Appointment::query()
            ->when($filters['field_office_id'] !== '', fn ($q) => $q->where('field_office_id', $filters['field_office_id']))
            ->when($filters['period'] !== 'yearly', fn ($q) => $q->whereBetween('date_received', [$filters['year'].'-01-01', $filters['year'].'-12-31']))
            ->selectRaw('date_received, count(*) as total, sum(is_pwd) as pwd, sum(is_indigenous) as indigenous, sum(is_solo_parent) as solo_parent')
            ->groupBy('date_received')
            ->get();

        return [$filters, $this->bucket($query, $filters['period'], $filters['year'])];
    }

    /**
     * Sums the per-day counts into the chosen periods. A month, quarter or semester with no appointments still
     * shows (as zero) so the table keeps its shape; yearly lists the years that have any.
     *
     * @return list<array<string, mixed>>
     */
    private function bucket(Collection $days, string $period, int $year): array
    {
        $keyOf = fn ($date) => match ($period) {
            'monthly' => $date->format('Y-m'),
            'quarterly' => $date->format('Y').'-Q'.$date->quarter,
            'semestral' => $date->format('Y').'-S'.($date->month <= 6 ? 1 : 2),
            'yearly' => $date->format('Y'),
        };

        $buckets = [];
        $blank = fn (string $key, string $label) => ['key' => $key, 'label' => $label, 'total' => 0, 'pwd' => 0, 'indigenous' => 0, 'solo_parent' => 0];

        if ($period === 'monthly') {
            foreach (range(1, 12) as $m) {
                $key = sprintf('%d-%02d', $year, $m);
                $buckets[$key] = $blank($key, CarbonImmutable::create($year, $m, 1)->format('F Y'));
            }
        } elseif ($period === 'quarterly') {
            foreach (['Jan–Mar', 'Apr–Jun', 'Jul–Sep', 'Oct–Dec'] as $i => $months) {
                $key = $year.'-Q'.($i + 1);
                $buckets[$key] = $blank($key, 'Q'.($i + 1)." {$year} ({$months})");
            }
        } elseif ($period === 'semestral') {
            foreach (['1st', '2nd'] as $i => $name) {
                $key = $year.'-S'.($i + 1);
                $buckets[$key] = $blank($key, "{$name} Semester {$year}");
            }
        }

        foreach ($days as $day) {
            $date = CarbonImmutable::parse($day->date_received);
            $key = $keyOf($date);
            $buckets[$key] ??= $blank($key, $date->format('Y'));
            foreach (['total', 'pwd', 'indigenous', 'solo_parent'] as $col) {
                $buckets[$key][$col] += (int) $day->{$col};
            }
        }

        ksort($buckets);

        return array_values($buckets);
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function totals(array $rows): array
    {
        return collect(['total', 'pwd', 'indigenous', 'solo_parent'])
            ->mapWithKeys(fn ($col) => [$col => array_sum(array_column($rows, $col))])->all();
    }

    /** @return list<int> */
    private function years(): array
    {
        return range(now()->year + 1, max(2020, now()->year - 10));
    }
}
