<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\FieldOffice;
use App\Services\Reports\MonthlyFigures;
use App\Services\Reports\ReportContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The single-pass MonthlyFigures must give exactly what the per-figure queries (the Appointment
 * scopes the reports used before) give, for every month, for one office and for all of them.
 */
class MonthlyFiguresTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_figure_equals_the_per_query_scopes_for_every_month(): void
    {
        mt_srand(20260);
        $offices = FieldOffice::factory()->count(3)->create();
        $actions = ['Approved', 'Approved', 'Disapproved', 'Invalidated', 'Validated', 'Pending', null];
        $eligibility = [null, 'N/A', 'CS Professional', 'CS Sub-professional'];

        foreach (range(1, 220) as $i) {
            $received = CarbonImmutable::create(2025, 11, 1)->addDays(mt_rand(0, 420));
            $action = $actions[array_rand($actions)];
            $actionDate = in_array($action, ['Approved', 'Disapproved', 'Invalidated', 'Validated'], true) || mt_rand(0, 9) === 0
                ? $received->addDays(mt_rand(0, 70))->toDateString()
                : null;

            Appointment::factory()->create([
                'field_office_id' => $offices->random()->id,
                'date_received' => $received->toDateString(),
                'sex' => mt_rand(0, 1) ? 'M' : 'F',
                'eligibility_type' => $eligibility[array_rand($eligibility)],
                'is_first_time_entrant' => mt_rand(0, 3) === 0,
                'csc_action' => $action,
                'action_date' => $actionDate,
            ]);
        }

        foreach ([null, $offices->first()] as $office) {
            $context = new ReportContext($office, 2026, 12);
            $figures = MonthlyFigures::compute($context, range(1, 12));

            foreach (range(1, 12) as $month) {
                $c = $context->forMonth($month);
                $received = fn () => $c->appointments()->receivedBetween($c->start, $c->end);
                $acted = fn () => $c->appointments()->actedBetween($c->start, $c->end);
                $before = fn () => $c->appointments()->where('date_received', '<', $c->start->toDateString());
                $firstTime = fn () => $c->appointments()->firstTimeApprovedBetween($c->start, $c->end);
                $eligible = $received()->whereNotNull('eligibility_type')->where('eligibility_type', '!=', 'N/A')->count();

                $expected = [
                    'received' => $received()->count(),
                    'eligible' => $eligible,
                    'opening_unacted' => $before()->unactedAsOf($c->start->subDay())->count(),
                    'closing_unacted_prior' => $before()->unactedAsOf($c->end)->count(),
                    'closing_unacted_received' => $received()->unactedAsOf($c->end)->count(),
                    'acted' => $acted()->count(),
                    'rejected' => $acted()->whereIn('csc_action', config('psed.rejected_actions'))->count(),
                    'first_time_male' => $firstTime()->where('sex', 'M')->count(),
                    'first_time_female' => $firstTime()->where('sex', 'F')->count(),
                    'acted_this_year' => $acted()->where('date_received', '>=', $c->yearStart->toDateString())->count(),
                    'acted_prior_years' => $acted()->where('date_received', '<', $c->yearStart->toDateString())->count(),
                ];

                $this->assertSame($expected, array_intersect_key($figures[$month], $expected), 'Month '.$month.($office ? ' (one office)' : ' (all offices)'));
            }
        }

        // The data really exercised the figures: not a test of zeroes against zeroes.
        $all = MonthlyFigures::compute(new ReportContext(null, 2026, 12), range(1, 12));
        $this->assertGreaterThan(50, array_sum(array_column($all, 'received')));
        $this->assertGreaterThan(20, array_sum(array_column($all, 'acted')));
        $this->assertGreaterThan(0, array_sum(array_column($all, 'first_time_male')));
        $this->assertGreaterThan(0, array_sum(array_column($all, 'acted_prior_years')));
        $this->assertGreaterThan(0, array_sum(array_column($all, 'opening_unacted')));
    }
}
