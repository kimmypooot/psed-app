<?php

namespace App\Http\Controllers;

use App\Enums\ReportType;
use App\Models\AuditLog;
use App\Models\FieldOffice;
use App\Models\User;
use App\Services\ExcelReportService;
use App\Services\LookupService;
use App\Services\ReportReadiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

        $data = $request->validate([
            'type' => ['required', Rule::enum(ReportType::class)],
            'month' => ['required', 'integer', 'between:1,12'],
            'year' => ['required', 'integer', 'between:2020,'.(now()->year + 1)],
            // PSED and administrators pick an office, or "all" for the consolidated report.
            'field_office_id' => $user->seesAllOffices() ? ['required', 'regex:/^(all|\d+)$/'] : ['nullable'],
        ]);

        $office = $this->officeFor($user, $data['field_office_id'] ?? null);
        $type = ReportType::from($data['type']);

        try {
            $file = $this->reports->generate($type, $office, (int) $data['year'], (int) $data['month']);
        } catch (RuntimeException $e) {
            report($e);

            return redirect()->route('reports.index')->with('error', $e->getMessage());
        }

        AuditLog::record('report.generate', $office, after: [
            'type' => $type->value, 'year' => (int) $data['year'], 'month' => (int) $data['month'],
            'office' => $office?->imis_code ?? 'all',
        ]);

        return response()->download($file['path'], $file['name'], [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * What would print as "N/A" or skew a figure in the chosen month's reports, so it can be fixed
     * first. For the same month and office as the download; the four reports share one check.
     */
    public function check(Request $request, ReportReadiness $readiness): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'month' => ['required', 'integer', 'between:1,12'],
            'year' => ['required', 'integer', 'between:2020,'.(now()->year + 1)],
            'field_office_id' => $user->seesAllOffices() ? ['required', 'regex:/^(all|\d+)$/'] : ['nullable'],
        ]);

        return response()->json($readiness->check(
            $this->officeFor($user, $data['field_office_id'] ?? null),
            (int) $data['year'],
            (int) $data['month'],
        ));
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