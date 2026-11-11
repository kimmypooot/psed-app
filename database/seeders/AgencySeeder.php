<?php

namespace Database\Seeders;

use App\Services\AgencyList;
use Illuminate\Database\Seeder;

/**
 * Loads the agency list from database/data/agencies.json, the copy last brought in
 * from PSED by `php artisan agencies:sync`. Run after FieldOfficeSeeder: each agency
 * is tied to the Field Office whose jurisdiction it falls in.
 */
class AgencySeeder extends Seeder
{
    public function run(AgencyList $list): void
    {
        $agencies = $list->fromJson();

        if ($agencies !== []) {
            $list->sync($agencies);
        }
    }
}
