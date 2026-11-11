<?php

namespace Database\Factories;

use App\Enums\HeadType;
use App\Models\FieldOffice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FieldOffice> */
class FieldOfficeFactory extends Factory
{
    protected $model = FieldOffice::class;

    public function definition(): array
    {
        $place = fake()->unique()->city();

        return [
            'name' => "Field Office-{$place}",
            'imis_code' => strtoupper(fake()->unique()->lexify('????')).'FO',
            'location' => $place.' City',
            'head_type' => HeadType::Standard,
            'prepared_by_name' => 'JUAN DELA CRUZ',
            'prepared_by_title' => 'Senior Personnel Specialist',
            'approved_by_name' => 'MARIA SANTOS',
            'approved_by_title' => 'Director II',
        ];
    }

    public function headedBy(HeadType $type): static
    {
        return $this->state(['head_type' => $type]);
    }
}
