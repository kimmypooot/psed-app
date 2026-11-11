<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An IMIS-linked staff stub (there are no passwords in this system).
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'imis_user_id' => fake()->unique()->numberBetween(1, 1_000_000),
            'username' => fake()->unique()->userName(),
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'position' => 'Senior Personnel Specialist',
            'role' => UserRole::Admin,
            'field_office_id' => null,
        ];
    }

    /** A PSED employee. */
    public function admin(): static
    {
        return $this->state(['role' => UserRole::Admin, 'field_office_id' => null]);
    }

    /** An employee of the given Field Office (by id). */
    public function fieldOffice(int $officeId): static
    {
        return $this->state(['role' => UserRole::FieldOffice, 'field_office_id' => $officeId]);
    }
}
