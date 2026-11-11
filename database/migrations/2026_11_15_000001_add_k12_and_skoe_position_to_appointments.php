<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // Feeds the K-12 Absorption sheet of the Integrated CSE Applicants and Absorption Database.
            $table->boolean('is_k12_graduate')->default(false)->after('is_solo_parent');

            // Only for the Sangguniang Kabataan Official Eligibility (SKOE): SK Official, SK Secretary or SK Treasurer.
            // Feeds the SK position breakdown of the SKOE Absorption sheet.
            $table->string('skoe_position', 20)->nullable()->after('eligibility_type');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['is_k12_graduate', 'skoe_position']);
        });
    }
};
