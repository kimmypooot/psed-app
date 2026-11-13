<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\FieldOffice;
use App\Models\SummaryAgencyAlias;
use App\Services\SummaryDatabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Pairing an agency CARES knows with the row of the Summary Database workbook it belongs to, when the two names
 * do not match by themselves; or saying it has no row of its own. PSED administrators only.
 */
class SummaryAgencyController extends Controller
{
    /** What the screen sends for "this agency has no row of its own". */
    public const NO_ROW = '__none__';

    public function index(SummaryDatabase $summary): Response
    {
        $this->authorize('manage-offices');

        try {
            $offices = $summary->review();
            $error = null;
        } catch (RuntimeException $e) {
            $offices = [];
            $error = $e->getMessage();
        }

        return Inertia::render('Settings/SummaryAgencies', ['offices' => $offices, 'error' => $error, 'noRow' => self::NO_ROW]);
    }

    public function update(Request $request, SummaryDatabase $summary): RedirectResponse
    {
        $this->authorize('manage-offices');

        $data = $request->validate([
            'items' => ['required', 'array', 'max:500'],
            'items.*.field_office_id' => ['required', 'integer', 'exists:field_offices,id'],
            'items.*.agency' => ['required', 'string', 'max:255'],
            'items.*.choice' => ['nullable', 'string', 'max:255'],
        ]);

        $review = collect($summary->review())->keyBy('id');
        $offices = FieldOffice::whereIn('id', collect($data['items'])->pluck('field_office_id'))->get()->keyBy('id');

        // A row that is not on the workbook, or an agency the office does not have, is not something to save.
        $errors = [];
        foreach ($data['items'] as $i => $item) {
            $office = $review->get($item['field_office_id']);
            $choice = $item['choice'] ?? '';

            if ($office === null || ! collect($office['agencies'])->contains('agency', $item['agency'])) {
                $errors["items.{$i}.agency"] = 'That agency is not one of this Field Office\'s.';
            } elseif ($choice !== '' && $choice !== self::NO_ROW && ! in_array($choice, $office['rows'], true)) {
                $errors["items.{$i}.choice"] = 'That is not a row of the workbook for this Field Office.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $changed = 0;
        DB::transaction(function () use ($data, $offices, $request, &$changed) {
            foreach ($data['items'] as $item) {
                $office = $offices[$item['field_office_id']];
                $choice = $item['choice'] ?? '';
                $existing = SummaryAgencyAlias::where('field_office_id', $office->id)->where('agency', $item['agency'])->first();

                if ($choice === '') {
                    if ($existing) {
                        AuditLog::record('agency.summary_unmap', $office, before: ['agency' => $item['agency'], 'row' => $existing->template_agency ?? 'no row of its own']);
                        $existing->delete();
                        $changed++;
                    }

                    continue;
                }

                $row = $choice === self::NO_ROW ? null : $choice;
                if ($existing && $existing->template_agency === $row) {
                    continue;
                }

                SummaryAgencyAlias::updateOrCreate(
                    ['field_office_id' => $office->id, 'agency' => $item['agency']],
                    ['template_agency' => $row, 'updated_by' => $request->user()->id],
                );
                AuditLog::record(
                    'agency.summary_map',
                    $office,
                    before: $existing ? ['agency' => $item['agency'], 'row' => $existing->template_agency ?? 'no row of its own'] : null,
                    after: ['agency' => $item['agency'], 'row' => $row ?? 'no row of its own'],
                );
                $changed++;
            }
        });

        return back()->with('status', $changed ? "{$changed} agenc".($changed === 1 ? 'y' : 'ies').' updated.' : 'Nothing changed.');
    }
}
