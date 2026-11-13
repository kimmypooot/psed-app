<?php

namespace App\Http\Controllers;

use App\Enums\ReportType;
use App\Models\AuditLog;
use App\Models\FieldOffice;
use App\Models\User;
use App\Services\ExcelReportService;
use App\Services\LookupService;
use App\Services\ReportReadiness;
use App\Services\Reports\ReportPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{
    public function __construct(
        private readonly ExcelReportService $reports,
        private readonly LookupService $lookups,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        // The reports are for the previous month until the new one has data.
        $default = now()->subMonthNoOverflow();

        return Inertia::render('Reports/Index', [
            'types' => collect(ReportType::cases())->map(fn (ReportType $t) => [
                'value' => $t->value,
                'label' => $t->label(),
                'full_name' => $t->fullName(),
                'description' => $t->description(),
            ])->all(),
            'offices' => $user->seesAllOffices() ? $this->lookups->offices() : [],
            'office' => $user->fieldOffice?->only('id', 'name'),
            'defaults' => [
                'month' => $default->month,
                'year' => $default->year,
                // PSED prepares the consolidated report by default; a Field Office only has its own.
                'field_office_id' => $user->seesAllOffices() ? 'all' : $user->field_office_id,
            ],
            'years' => range(now()->year, now()->year - 5),
        ]);
    }

    /**
     * Hydrates the CSC's template for the chosen report and sends the file.
     * A GET, so the browser downloads it directly (no Inertia round trip).
     */
    public function download(Request $request): BinaryFileResponse|RedirectResponse
    {
        $user = $request->user();

        // A plain download: a refused choice comes back to this page as a message, not as a form error nobody sees.
        try {
            [$period, $data] = $this->period($request, [
                'type' => ['required', Rule::enum(ReportType::class)],
                // PSED and administrators pick an office, or "all" for the consolidated report.
                'field_office_id' => $user->seesAllOffices() ? ['required', 'regex:/^(all|\d+)$/'] : ['nullable'],
            ]);
        } catch (ValidationException $e) {
            return redirect()->route('reports.index')->withErrors($e->errors())->with('error', collect($e->errors())->flatten()->first());
        }

        $office = $this->officeFor($user, $data['field_office_id'] ?? null);
        $type = ReportType::from($data['type']);

        // The IPMR and MRAP have a column per month: a range of dates that cuts a month in two has no column to go in.
        if ($period->kind === ReportPeriod::CUSTOM && in_array($type, [ReportType::Ipmr, ReportType::Mrap], true) && ! $period->wholeMonthsInOneYear()) {
            return redirect()->route('reports.index')->with('error', "The {$type->label()} has a column for each month. Choose whole months in one year (from the first day of a month to the last day of a month), or use a month, quarter or year.");
        }

        try {
            $file = $this->reports->generateFor($type, $office, $period);
        } catch (RuntimeException $e) {
            report($e);

            return redirect()->route('reports.index')->with('error', $e->getMessage());
        }

        AuditLog::record('report.generate', $office, after: [
            'type' => $type->value, 'year' => $period->from->year, 'month' => $period->from->month,
            'period' => $period->label, 'kind' => $period->kind,
            'office' => $office?->imis_code ?? 'all',
        ]);

        return response()->download($file['path'], $file['name'], [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * What would print as "N/A" or skew a figure in the chosen period's reports, so it can be fixed
     * first. For the same period and office as the download; the four reports share one check.
     */
    public function check(Request $request, ReportReadiness $readiness): JsonResponse
    {
        $user = $request->user();

        [$period, $data] = $this->period($request, [
            'field_office_id' => $user->seesAllOffices() ? ['required', 'regex:/^(all|\d+)$/'] : ['nullable'],
        ]);

        return response()->json($readiness->checkPeriod($this->officeFor($user, $data['field_office_id'] ?? null), $period) + ['period' => $period->label]);
    }

    /**
     * The period asked for: a year, quarter, month or range of dates. A request with just a month and a year
     * (the monthly form) is a month.
     *
     * @param  array<string, mixed>  $extraRules
     * @return array{0: ReportPeriod, 1: array<string, mixed>}
     */
    private function period(Request $request, array $extraRules): array
    {
        if (! $request->has('kind')) {
            $request->merge(['kind' => ReportPeriod::MONTH]);
        }

        $data = $request->validate(ReportPeriod::rules() + $extraRules, ReportPeriod::messages());

        return [ReportPeriod::fromInput($data), $data];
    }

    /**
     * The office a report is about: PSED and administrators choose one, or "all" for the
     * consolidated report; Field Office staff always get their own, whatever was asked for.
     */
    private function officeFor(User $user, ?string $requested): ?FieldOffice
    {
        return match (true) {
            ! $user->seesAllOffices() => $user->fieldOffice,
            $requested === 'all' => null,
            default => FieldOffice::findOrFail($requested),
        };
    }
}