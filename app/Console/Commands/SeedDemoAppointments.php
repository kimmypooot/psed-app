<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\FieldOffice;
use App\Models\ReferenceValue;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Demo appointments spread over every Field Office (each from its own agency list), so the dashboard,
 * the list, the PSED monitoring table, the routing rule and the reports can be seen with realistic data.
 * Saved through the model, so each one is routed to PSED or the Field Office exactly as a real one is.
 * Every demo row is encoded by "Demo Encoder", which is how --fresh finds them again.
 */
class SeedDemoAppointments extends Command
{
    protected $signature = 'appointments:seed-demo {count=200 : How many appointments} {--fresh : Remove earlier demo rows first}';

    protected $description = 'Seed demo appointments across all Field Offices (development only)';

    private const TAG = 'Demo Encoder';

    /** [position, salary grade] */
    private const POSITIONS = [
        ['Administrative Aide I (Utility Worker I)', 1], ['Administrative Aide III (Clerk I)', 3],
        ['Administrative Aide IV (Clerk II)', 4], ['Administrative Aide VI (Clerk III)', 6],
        ['Administrative Assistant I (Computer Operator I)', 7], ['Administrative Assistant II (Disbursing Officer II)', 8],
        ['Administrative Assistant III (Cashier I)', 9], ['Teacher I (Elementary)', 11], ['Teacher III', 15],
        ['Administrative Officer II (Administrative Officer I)', 11], ['Nurse I', 15], ['Engineer I', 15],
        ['Social Welfare Officer I', 11], ['Municipal Agriculturist', 22], ['Administrative Officer IV', 15],
        ['Accountant II', 16], ['Planning Officer III', 18], ['Budget Officer III', 18],
        ['Municipal Government Department Head I', 24], ['Municipal Government Department Head II', 26],
        ['Provincial Government Department Head I', 25], ['Provincial Government Department Head II', 27],
        ['School Principal IV', 22], ['Schools Division Superintendent', 26], ['Chief Administrative Officer', 24],
    ];

    private const LAST = ['Abella', 'Aquino', 'Balbuena', 'Cabrera', 'Camacho', 'Dacillo', 'Estoque', 'Flores', 'Gabon', 'Hermano', 'Ibañez', 'Jumao-as', 'Lacaba', 'Mercado', 'Nacario', 'Ocampo', 'Pacatang', 'Quinto', 'Ranola', 'Salazar', 'Tacoy', 'Uy', 'Villarin', 'Yuson'];

    private const FIRST_M = ['Juan', 'Jose', 'Mark', 'Ryan', 'Carlo', 'Noel', 'Rey', 'Allan', 'Jerome', 'Dennis'];

    private const FIRST_F = ['Maria', 'Ana', 'Grace', 'Liza', 'Joy', 'Rosa', 'Mary Ann', 'Cheryl', 'Elena', 'Jocelyn'];

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing to seed demo data in production.');

            return self::FAILURE;
        }

        $offices = FieldOffice::orderBy('id')->get();

        if ($offices->isEmpty()) {
            $this->error('There are no Field Offices yet. Run the seeders first.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->info('Removed '.Appointment::where('received_by', self::TAG)->delete().' earlier demo appointments.');
        }

        $agencies = ReferenceValue::where('type', 'agency')->whereNotNull('field_office_id')->get(['value', 'field_office_id', 'id'])->groupBy('field_office_id');
        $eligibilities = ReferenceValue::where('type', 'eligibility_type')->pluck('value')->all();
        $count = (int) $this->argument('count');
        $today = CarbonImmutable::today();
        $created = [];

        DB::transaction(function () use ($count, $offices, $agencies, $eligibilities, $today, &$created) {
            for ($i = 0; $i < $count; $i++) {
                // Round-robin so every office gets its share (nobody is left with none), then a little noise.
                $office = $offices[$i % $offices->count()];
                $officeAgencies = $agencies->get($office->id) ?? collect();

                if ($officeAgencies->isEmpty()) {
                    continue;
                }

                $agency = $officeAgencies->random()->value;
                [$position, $sg] = self::POSITIONS[array_rand(self::POSITIONS)];
                $sex = mt_rand(0, 1) ? 'M' : 'F';
                $received = $today->subDays(mt_rand(0, 100));
                $eligibility = $sg <= 4 && mt_rand(0, 2) === 0 ? null : $eligibilities[array_rand($eligibilities)];

                // Roughly 70% decided, 20% still pending, 10% not yet touched; a few are disapproved or invalidated.
                $roll = mt_rand(1, 100);
                $action = match (true) {
                    $roll <= 52 => 'Approved',
                    $roll <= 60 => 'Validated',
                    $roll <= 65 => 'Disapproved',
                    $roll <= 70 => 'Invalidated',
                    $roll <= 90 => 'Pending',
                    default => null,
                };
                $decided = in_array($action, ['Approved', 'Validated', 'Disapproved', 'Invalidated'], true);
                $actionDate = $decided ? min($received->addDays(mt_rand(1, 20)), $today) : null;

                $appointment = Appointment::create([
                    'field_office_id' => $office->id,
                    'agency' => $agency,
                    'sector' => str_starts_with($agency, 'MG ') || str_starts_with($agency, 'PG ') || str_starts_with($agency, 'CG ') ? 'Local Government Unit' : 'National Government Agency',
                    'date_received' => $received,
                    'time_received' => sprintf('%02d:%02d:00', mt_rand(8, 16), mt_rand(0, 59)),
                    'received_by' => self::TAG,
                    'date_issued' => $received->subDays(5),
                    'date_effective' => $received->subDays(5),
                    'last_name' => self::LAST[array_rand(self::LAST)],
                    'first_name' => ($sex === 'M' ? self::FIRST_M : self::FIRST_F)[mt_rand(0, 9)],
                    'middle_name' => self::LAST[array_rand(self::LAST)],
                    'sex' => $sex,
                    'position' => $position,
                    'salary_grade' => $sg,
                    'level' => $sg <= 8 ? '1st' : '2nd',
                    'employment_status' => ['Permanent', 'Permanent', 'Permanent', 'Casual', 'Contractual', 'Temporary'][mt_rand(0, 5)],
                    'nature_of_appointment' => ['Original', 'Original', 'Promotion', 'Reappointment', 'Transfer', 'Reemployment'][mt_rand(0, 5)],
                    'publication_start' => $sg > 4 ? $received->subDays(40) : null,
                    'publication_end' => $sg > 4 ? $received->subDays(30) : null,
                    'publication_mode' => $sg > 4 ? 'CSC Bulletin of Vacant Positions' : null,
                    'eligibility_type' => $eligibility,
                    'is_first_time_entrant' => $eligibility !== null && mt_rand(0, 3) === 0,
                    'eligibility_exam_date' => $eligibility ? '03/17/2024' : null,
                    'eligibility_exam_place' => $eligibility ? 'Tacloban City, Leyte' : null,
                    'eligibility_issued_date' => $eligibility ? '2024-06-14' : null,
                    'eligibility_issued_place' => $eligibility ? 'Palo, Leyte' : null,
                    'is_pwd' => mt_rand(1, 25) === 1,
                    'is_indigenous' => mt_rand(1, 40) === 1,
                    'is_solo_parent' => mt_rand(1, 20) === 1,
                    'csc_action' => $action,
                    'action_officer' => $decided ? 'Demo Action Officer' : null,
                    'action_date' => $actionDate,
                    'release_date' => in_array($action, ['Approved', 'Validated'], true) ? min($actionDate->addDays(mt_rand(0, 3)), $today) : null,
                    'pending_reason' => $action === 'Pending' ? 'Awaiting the appointing authority\'s requirements.' : null,
                    'disapproval_reason' => in_array($action, ['Disapproved', 'Invalidated'], true) ? 'Does not meet the qualification standards.' : null,
                ]);

                $created[$office->name]['fo'] = ($created[$office->name]['fo'] ?? 0) + ($appointment->processed_by->value === 'field_office' ? 1 : 0);
                $created[$office->name]['psed'] = ($created[$office->name]['psed'] ?? 0) + ($appointment->processed_by->value === 'psed' ? 1 : 0);
            }
        });

        $this->table(['Field Office', 'Processed by the office', 'Processed by PSED', 'Total'], collect($created)->map(
            fn ($n, $name) => [$name, $n['fo'], $n['psed'], $n['fo'] + $n['psed']]
        )->values()->all());
        $this->info('Seeded '.collect($created)->sum(fn ($n) => $n['fo'] + $n['psed']).' demo appointments (encoded by "'.self::TAG.'").');

        return self::SUCCESS;
    }
}
