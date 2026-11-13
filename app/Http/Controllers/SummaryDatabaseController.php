<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\Reports\ReportPeriod;
use App\Services\SummaryDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The Integrated CSE Applicants and Absorption Database Summary, prepared by PSED for the whole region
 * and for a year, quarter, month or range of dates. PSED administrators only.
 */
class SummaryDatabaseController extends Controller
{
    public function index(): Response
    {
        $this->authorize('view-summary-database');

        return Inertia::render('Reports/SummaryDatabase', [
            'years' => range(now()->year, now()->year - 5),
            'today' => now()->toDateString(),
        ]);
    }

    /** The period's totals and the agencies that did not match a row of the workbook, before downloading. */
    public function check(Request $request, SummaryDatabase $summary): JsonResponse
    {
        $this->authorize('view-summary-database');

        return response()->json($summary->check($this->period($request)));
    }

    public function download(Request $request, SummaryDatabase $summary): BinaryFileResponse|RedirectResponse
    {
        $this->authorize('view-summary-database');

        $period = $this->period($request);

        try {
            $file = $summary->generate($period);
        } catch (RuntimeException $e) {
            report($e);

            return redirect()->route('reports.summary-database')->with('error', $e->getMessage());
        }

        AuditLog::record('report.summary_database', after: [
            'period' => $period->label, 'kind' => $period->kind,
            'from' => $period->from->toDateString(), 'to' => $period->to->toDateString(),
        ]);

        return response()->download($file['path'], $file['name'], [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    private function period(Request $request): ReportPeriod
    {
        return ReportPeriod::fromInput($request->validate(ReportPeriod::rules(), ReportPeriod::messages()));
    }
}
