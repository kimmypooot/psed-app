<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * No users are seeded: staff only ever exist here after signing in from IMIS.
 * DemoAppointmentSeeder adds sample appointments for development; skip it in production.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ReferenceDataSeeder::class,
            FieldOfficeSeeder::class,
            AgencySeeder::class,
        ]);

        if (app()->environment('local', 'testing')) {
            $this->call(DemoAppointmentSeeder::class);
        }
    }
}
