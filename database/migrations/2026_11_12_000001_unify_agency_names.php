<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Renames agencies already encoded under the old short names to the PSED-wide agency list. */
return new class extends Migration
{
    private const MAP = [
        'CG Borongan' => 'City Government of Borongan',
        'DepEd Borongan City Division' => 'Department of Education - Schools Division Office of Borongan City',
        'DepEd Eastern Samar Division' => 'Department of Education - Schools Division Office of Eastern Samar',
        'PG Eastern Samar' => 'Provincial Government of Eastern Samar',
        'MG Arteche' => 'Municipal Government of Arteche, Eastern Samar',
        'MG Balangiga' => 'Municipal Government of Balangiga, Eastern Samar',
        'MG Balangkayan' => 'Municipal Government of Balangkayan, Eastern Samar',
        'MG Can-avid' => 'Municipal Government of Can-Avid, Eastern Samar',
        'MG Dolores' => 'Municipal Government of Dolores, Eastern Samar',
        'MG General MacArthur' => 'Municipal Government of Gen. Macarthur, Eastern Samar',
        'MG Giporlos' => 'Municipal Government of Giporlos, Eastern Samar',
        'MG Guiuan' => 'Municipal Government of Guiuan, Eastern Samar',
        'MG Hernani' => 'Municipal Government of Hernani, Eastern Samar',
        'MG Jipapad' => 'Municipal Government of Jipapad',
        'MG Lawaan' => 'Municipal Government of Lawaan, Eastern Samar',
        'MG Llorente' => 'Municipal Government of Llorente',
        'MG Maslog' => 'Municipal Government of Maslog',
        'MG Maydolong' => 'Municipal Government of Maydolong',
        'MG Mercedes' => 'Municipal Government of Mercedes, Eastern Samar',
        'MG Oras' => 'Municipal Government of Oras, Eastern Samar',
        'MG Quinapondan' => 'Municipal Government of Quinapondan, Eastern Samar',
        'MG Salcedo' => 'Municipal Government of Salcedo, Eastern Samar',
        'MG San Julian' => 'Municipal Government of San Julian, Eastern Samar',
        'MG San Policarpo' => 'Municipal Government of San Policarpo, Eastern Samar',
        'MG Sulat' => 'Municipal Government of Sulat, Eastern Samar',
        'MG Taft' => 'Municipal Government of Taft, Eastern Samar',
    ];

    public function up(): void
    {
        foreach (self::MAP as $old => $new) {
            DB::table('appointments')->where('agency', $old)->update(['agency' => $new]);
        }
    }

    public function down(): void
    {
        foreach (self::MAP as $old => $new) {
            DB::table('appointments')->where('agency', $new)->update(['agency' => $old]);
        }
    }
};
