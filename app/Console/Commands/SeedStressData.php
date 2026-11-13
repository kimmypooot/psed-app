<?php

namespace App\Console\Commands;

use App\Enums\HeadType;
use App\Models\FieldOffice;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills a THROWAWAY database with realistic appointment volumes so pages, queries and
 * indexes can be measured at scale. Refuses to run unless the database name contains
 * "stress", so it can never touch real data.
 */
class SeedStressData extends Command
{
    protected $signature = 'stress:seed {count=100000 : How many appointments} {--years=5 : Spread over this many years}';

    protected $description = 'Seed a "stress" database with many fake appointments (load testing only)';

    public function handle(): int
    {
        $db = (string) DB::connection()->getDatabaseName();

        if (! str_contains($db, 'stress') || app()->isProduction()) {
            $this->error("Refusing to seed '{$db}': the database name must contain 'stress'.");

            return self::FAILURE;
        }

        $count = (int) $this->argument('count');
        $years = max(1, (int) $this->option('years'));

        if (FieldOffice::count() === 0) {
            foreach (range(1, 8) as $i) {
                FieldOffice::create([
                    'name' => "Stress Field Office {$i}",
                    'imis_code' => "STRESS{$i}",
                    'head_type' => [HeadType::DirectorII, HeadType::OicSg22, HeadType::Standard][$i % 3],
                ]);
            }
        }

        $offices = FieldOffice::all()->map(fn ($o) => [$o->id, $o->head_type->psedThreshold()])->all();
        $agencies = array_map(fn ($i) => "Agency number {$i} - Provincial Office of Region VIII", range(1, 200));
        $positions = array_map(fn ($i) => "Position title {$i}", range(1, 300));
        $actions = ['Approved', 'Approved', 'Approved', 'Disapproved', 'Invalidated', 'Validated', 'Pending', null];
        $statuses = ['Permanent', 'Casual', 'Contractual', 'Temporary'];
        $first = ['Maria', 'Juan', 'Jose', 'Ana', 'Pedro', 'Rosa', 'Carlo', 'Liza', 'Mark', 'Grace', 'Ryan', 'Joy'];
        $last = ['Santos', 'Reyes', 'Cruz', 'Garcia', 'Mendoza', 'Torres', 'Flores', 'Ramos', 'Aquino', 'Abella', 'Villanueva', 'Estoque'];
        $start = CarbonImmutable::today()->subYears($years)->getTimestamp();
        $span = CarbonImmutable::today()->getTimestamp() - $start;
        $now = now()->toDateTimeString();

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        foreach (array_chunk(range(1, $count), 2000) as $chunk) {
            $rows = [];

            foreach ($chunk as $_) {
                [$officeId, $threshold] = $offices[array_rand($offices)];
                $received = CarbonImmutable::createFromTimestamp($start + random_int(0, $span));
                $sg = random_int(1, 30);
                $action = $actions[array_rand($actions)];
                $acted = in_array($action, ['Approved', 'Disapproved', 'Invalidated', 'Validated'], true);
                $actionDate = $acted ? $received->addDays(random_int(1, 20))->toDateString() : null;

                $rows[] = [
                    'field_office_id' => $officeId,
                    'agency' => $agencies[array_rand($agencies)],
                    'sector' => 'National Government Agency',
                    'date_received' => $received->toDateString(),
                    'time_received' => '10:30:00',
                    'received_by' => 'Stress Tester',
                    'date_issued' => $received->toDateString(),
                    'date_effective' => $received->toDateString(),
                    'last_name' => $last[array_rand($last)].random_int(1, 999),
                    'first_name' => $first[array_rand($first)],
                    'middle_name' => $last[array_rand($last)],
                    'sex' => random_int(0, 1) ? 'M' : 'F',
                    'position' => $positions[array_rand($positions)],
                    'salary_grade' => $sg,
                    'level' => '1st',
                    'employment_status' => $statuses[array_rand($statuses)],
                    'nature_of_appointment' => 'Original',
                    'is_first_time_entrant' => random_int(0, 9) === 0,
                    'csc_action' => $action,
                    'action_officer' => $acted ? 'Stress Officer' : null,
                    'action_date' => $actionDate,
                    'processed_by' => $sg >= $threshold ? 'psed' : 'field_office',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('appointments')->insert($rows);
            $bar->advance(count($chunk));
        }

        $bar->finish();
        $this->newLine();
        $this->info('Seeded '.number_format(DB::table('appointments')->count()).' appointments in '.$db.'.');

        return self::SUCCESS;
    }
}
