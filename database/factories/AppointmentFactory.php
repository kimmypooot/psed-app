<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\FieldOffice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Appointment> */
class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    public function definition(): array
    {
        $received = fake()->dateTimeBetween('2026-10-01', '2026-10-28');

        return [
            'field_office_id' => FieldOffice::factory(),
            'agency' => 'Department of Education - Schools Division Office of Eastern Samar',
            'sector' => 'National Government Agency',
            'date_received' => $received,
            'time_received' => '10:30:00',
            'received_by' => 'Ryan Jose M. Estoque',
            'date_issued' => $received,
            'date_effective' => $received,
            'last_name' => fake()->lastName(),
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->lastName(),
            'sex' => fake()->randomElement(['M', 'F']),
            'position' => 'Administrative Aide I (Utility Worker I)',
            'salary_grade' => 1,
            'level' => '1st',
            'employment_status' => 'Permanent',
            'nature_of_appointment' => 'Original',
            'is_first_time_entrant' => false,
            'is_pwd' => false,
            'is_indigenous' => false,
            'is_solo_parent' => false,
            'is_k12_graduate' => false,
        ];
    }

    /** Eligible through the Sangguniang Kabataan Official Eligibility, for the given SK position. */
    public function skoe(string $position = 'SK Official'): static
    {
        return $this->state([
            'eligibility_type' => config('psed.skoe.eligibility'),
            'skoe_position' => $position,
        ]);
    }

    public function casual(): static
    {
        return $this->state(['employment_status' => 'Casual']);
    }

    public function withEligibility(string $type = 'CS Professional'): static
    {
        return $this->state([
            'eligibility_type' => $type,
            'eligibility_exam_date' => '03/17/2024',
            'eligibility_exam_place' => 'Tacloban City, Leyte',
            'eligibility_issued_date' => '2024-06-14',
            'eligibility_issued_place' => 'Palo, Leyte',
        ]);
    }

    public function firstTime(): static
    {
        return $this->withEligibility()->state(['is_first_time_entrant' => true]);
    }

    /** Acted on the given date ("Y-m-d"). */
    public function acted(string $date, string $action = 'Approved'): static
    {
        return $this->state([
            'csc_action' => $action,
            'action_officer' => 'Rey Albert B. Uy',
            'action_date' => $date,
            'release_date' => $date,
        ]);
    }

    public function pending(): static
    {
        return $this->state(['csc_action' => 'Pending', 'pending_reason' => 'Lacks a required document.']);
    }
}
