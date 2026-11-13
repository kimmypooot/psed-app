<?php

namespace App\Services\Reports;

use App\Services\OpeningBalances;
use Illuminate\Support\Facades\DB;

/**
 * The monthly counts the IPMR and MRAP are built from, for several months at once.
 *
 * Each report used to run a separate COUNT per figure per month; the consolidated report
 * (every Field Office, January to the chosen month) did about 70 of them, and the unacted-as-of
 * ones cannot use an index (they are OR conditions), so every one scanned the whole table.
 * Here every figure of every month is a conditional SUM in ONE pass over the appointments,
 * grouped by Field Office.
 *
 * The backlog is split in two. What came in during the report's year is counted from the appointments.
 * What was carried in from the year before is the office's opening balance (OpeningBalances: set by PSED,
 * or worked out from the appointments when PSED set nothing) less what the system has seen acted since
 * 1 January. Worked out from the appointments, that gives exactly the old single count, which
 * MonthlyFiguresTest checks against the Appointment scopes; set by PSED, it lets the year start from a balance
 * the system never held, and it never goes below zero for an office.
 */
final class MonthlyFigures
{
    /**
     * @param  list<int>  $months  1-12
     * @return array<int, array<string, int>> per month: received, eligible, opening_unacted, closing_unacted_prior,
     *                                        closing_unacted_received, acted, rejected, first_time_male, first_time_female,
     *                                        acted_this_year, acted_prior_years, and the carried-over backlog:
     *                                        balance (G.1), backlog_start (G.2), backlog_acted (G.3), backlog_end (G.4)
     */
    public static function compute(ReportContext $context, array $months): array
    {
        $finals = config('psed.final_actions');
        $approved = config('psed.approved_actions');
        $rejected = config('psed.rejected_actions');
        $marks = fn (array $list): string => implode(',', array_fill(0, count($list), '?'));

        $select = [];
        $bindings = [];

        // Appends `COALESCE(SUM(<condition>), 0) AS <alias>` with its bindings.
        $add = function (string $alias, string $condition, array $values) use (&$select, &$bindings): void {
            $select[] = "COALESCE(SUM({$condition}), 0) AS {$alias}";
            array_push($bindings, ...$values);
        };

        $unactedAsOf = '(action_date IS NULL OR csc_action IS NULL OR csc_action = \'Pending\' OR action_date > ?)';
        $yearStart = $context->yearStart->toDateString();
        $yearEndBefore = $context->yearStart->subDay()->toDateString();

        // What the appointments themselves say was unacted when the year began (used when PSED set no balance).
        $add('balance_data', "date_received < ? AND {$unactedAsOf}", [$yearStart, $yearEndBefore]);

        foreach ($months as $month) {
            $c = $context->forMonth($month);
            [$start, $end, $before] = [$c->start->toDateString(), $c->end->toDateString(), $c->start->subDay()->toDateString()];

            $received = 'date_received BETWEEN ? AND ?';
            $acted = "action_date IS NOT NULL AND csc_action IN ({$marks($finals)}) AND action_date BETWEEN ? AND ?";
            $actedValues = [...$finals, $start, $end];
            // Received during the year, before this month.
            $earlierThisYear = 'date_received >= ? AND date_received < ?';
            // Received before the year, acted during it: they work the carried-over balance down.
            $carried = "date_received < ? AND csc_action IN ({$marks($finals)}) AND action_date BETWEEN ? AND ?";

            $add("m{$month}_received", $received, [$start, $end]);
            $add("m{$month}_eligible", "({$received}) AND eligibility_type IS NOT NULL AND eligibility_type != 'N/A'", [$start, $end]);
            $add("m{$month}_opening_year", "({$earlierThisYear}) AND {$unactedAsOf}", [$yearStart, $start, $before]);
            $add("m{$month}_closing_year", "({$earlierThisYear}) AND {$unactedAsOf}", [$yearStart, $start, $end]);
            $add("m{$month}_closing_received", "({$received}) AND {$unactedAsOf}", [$start, $end, $end]);
            $add("m{$month}_acted", $acted, $actedValues);
            $add("m{$month}_rejected", "({$acted}) AND csc_action IN ({$marks($rejected)})", [...$actedValues, ...$rejected]);

            $firstTime = "is_first_time_entrant = 1 AND csc_action IN ({$marks($approved)}) AND action_date BETWEEN ? AND ?";
            $firstTimeValues = [...$approved, $start, $end];
            $add("m{$month}_first_m", "({$firstTime}) AND sex = 'M'", $firstTimeValues);
            $add("m{$month}_first_f", "({$firstTime}) AND sex = 'F'", $firstTimeValues);
            $add("m{$month}_acted_year", "({$acted}) AND date_received >= ?", [...$actedValues, $yearStart]);
            $add("m{$month}_acted_prior", "({$acted}) AND date_received < ?", [...$actedValues, $yearStart]);
            // Carried-over appointments acted from 1 January up to the day before the month, and up to its end.
            $add("m{$month}_carried_before", $carried, [$yearStart, ...$finals, $yearStart, $before]);
            $add("m{$month}_carried_through", $carried, [$yearStart, ...$finals, $yearStart, $end]);
        }

        $rows = DB::table('appointments')
            ->when($context->office, fn ($q, $office) => $q->where('field_office_id', $office->id))
            ->selectRaw('field_office_id, '.implode(', ', $select), $bindings)
            ->groupBy('field_office_id')
            ->get()->keyBy('field_office_id');

        // An office with a balance set but no appointments yet still carries that balance.
        $overrides = app(OpeningBalances::class)->overrides($context->year);
        if ($context->office) {
            $overrides = array_intersect_key($overrides, [$context->office->id => true]);
        }
        $officeIds = array_unique([...$rows->keys()->all(), ...array_keys($overrides)]);

        $keys = ['received', 'eligible', 'opening_unacted', 'closing_unacted_prior', 'closing_unacted_received', 'acted', 'rejected',
            'first_time_male', 'first_time_female', 'acted_this_year', 'acted_prior_years', 'balance', 'backlog_start', 'backlog_acted', 'backlog_end'];
        $figures = [];

        foreach ($months as $month) {
            $figures[$month] = array_fill_keys($keys, 0);
        }

        foreach ($officeIds as $officeId) {
            $row = (array) ($rows[$officeId] ?? []);
            $get = fn (string $key): int => (int) ($row[$key] ?? 0);

            // PSED's figure when set, else what the appointments say.
            $balance = $overrides[$officeId] ?? $get('balance_data');

            foreach ($months as $month) {
                $start = max($balance - $get("m{$month}_carried_before"), 0);
                $end = max($balance - $get("m{$month}_carried_through"), 0);
                $f = &$figures[$month];

                $f['received'] += $get("m{$month}_received");
                $f['eligible'] += $get("m{$month}_eligible");
                $f['opening_unacted'] += $get("m{$month}_opening_year") + $start;
                $f['closing_unacted_prior'] += $get("m{$month}_closing_year") + $end;
                $f['closing_unacted_received'] += $get("m{$month}_closing_received");
                $f['acted'] += $get("m{$month}_acted");
                $f['rejected'] += $get("m{$month}_rejected");
                $f['first_time_male'] += $get("m{$month}_first_m");
                $f['first_time_female'] += $get("m{$month}_first_f");
                $f['acted_this_year'] += $get("m{$month}_acted_year");
                $f['acted_prior_years'] += $get("m{$month}_acted_prior");
                $f['balance'] += $balance;
                $f['backlog_start'] += $start;
                $f['backlog_acted'] += $start - $end;
                $f['backlog_end'] += $end;
                unset($f);
            }
        }

        return $figures;
    }
}
