<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\FieldOffice;
use App\Services\OpeningBalances;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The balance each Field Office carries into a year (its appointments unacted on 31 December of the year before),
 * which feeds the MRAP's Beginning Balance and section G. PSED, who consolidates the reports, sets it; left blank,
 * the system works it out from its own appointments. PSED administrators only.
 */
class OpeningBalanceController extends Controller
{
    public function index(Request $request, OpeningBalances $balances): Response
    {
        $this->authorize('manage-offices');

        $year = $this->year($request);
        $set = $balances->setFor($year);
        // The system can only suggest a balance once it holds appointments from before the year (from the second year on).
        $hasSystemData = Appointment::where('date_received', '<', $year.'-01-01')->exists();
        $system = $hasSystemData ? $balances->fromSystem($year) : [];

        return Inertia::render('Settings/OpeningBalances', [
            'year' => $year,
            'has_system_data' => $hasSystemData,
            'years' => range(now()->year + 1, now()->year - 2),
            'offices' => FieldOffice::orderBy('name')->get(['id', 'name'])->map(function (FieldOffice $o) use ($set, $system, $hasSystemData) {
                $row = $set->get($o->id);

                return [
                    'id' => $o->id,
                    'name' => $o->name,
                    'system' => $hasSystemData ? ($system[$o->id] ?? 0) : null,
                    'unacted' => $row?->unacted,
                    'note' => $row?->note,
                    'set_by' => $row?->updater?->name,
                    'set_at' => $row?->updated_at?->toIso8601String(),
                ];
            })->values(),
        ]);
    }

    public function update(Request $request, OpeningBalances $balances): RedirectResponse
    {
        $this->authorize('manage-offices');

        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2020,'.(now()->year + 1)],
            'balances' => ['required', 'array'],
            'balances.*.field_office_id' => ['required', 'integer', 'exists:field_offices,id'],
            'balances.*.unacted' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'balances.*.note' => ['nullable', 'string', 'max:255'],
        ], [
            'balances.*.unacted.integer' => 'The balance must be a whole number.',
            'balances.*.unacted.min' => 'The balance cannot be negative.',
            'balances.*.unacted.max' => 'That balance is too large.',
        ]);

        $offices = FieldOffice::whereIn('id', collect($data['balances'])->pluck('field_office_id'))->get()->keyBy('id');

        DB::transaction(function () use ($data, $offices, $balances, $request) {
            foreach ($data['balances'] as $row) {
                $balances->set(
                    $offices[$row['field_office_id']],
                    (int) $data['year'],
                    isset($row['unacted']) && $row['unacted'] !== '' ? (int) $row['unacted'] : null,
                    $row['note'] ?? null,
                    $request->user(),
                );
            }
        });

        return back()->with('status', "The {$data['year']} opening balances were saved.");
    }

    private function year(Request $request): int
    {
        $year = (int) $request->query('year');

        // From October the balance everyone is preparing is next year's.
        return $year >= 2020 && $year <= now()->year + 1 ? $year : (now()->month >= 10 ? now()->year + 1 : now()->year);
    }
}
