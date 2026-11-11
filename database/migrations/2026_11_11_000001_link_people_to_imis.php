<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * People are IMIS employees, not typed names. The name stays on the row
     * (the reports print it, and it survives if IMIS is unreachable or the
     * person leaves), and the id says which IMIS employee it is.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->unsignedBigInteger('action_officer_imis_id')->nullable()->after('action_officer')->index();
        });

        // Who prepares an office's reports: one of the staff IMIS lists in that office. (Who approves
        // them is not stored: it is the office's head in IMIS, read when the report is made.)
        Schema::table('field_offices', function (Blueprint $table) {
            $table->unsignedBigInteger('prepared_by_imis_id')->nullable()->after('head_type');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['action_officer_imis_id']);
            $table->dropColumn('action_officer_imis_id');
        });
        Schema::table('field_offices', function (Blueprint $table) {
            $table->dropColumn('prepared_by_imis_id');
        });
    }
};
